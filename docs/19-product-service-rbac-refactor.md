# Unified ProductService and Spatie RBAC refactor

Implementation date: **October 7, 2026** (Africa/Cairo). This approved follow-up consolidates product application services and replaces boolean administration with a small permission-based model. Earlier milestone/audit documents retain their historical evidence. At the time of this refactor, `docs/18-structural-integrity-audit.md` was preserved unchanged and excluded from the refactor commit; its separate ProductAdministrationService and boolean-authorization findings describe the pre-refactor state.

**Later release status:** this report preserves the refactor-time method signatures and test evidence. Commit `16faf77` subsequently replaced authentication and admin product/promotion array inputs with [seven typed DTOs](../app/DTOs/README.md); `3043567` made the rate-limit fixture PSR-4 compliant. The [structural audit's superseding status](18-structural-integrity-audit.md#superseding-release-status--october-8-2026) identifies the final architecture and later verification. The audit was excluded from the original refactor commit and is now included with that status clarification.

## Git and baseline

Work started from `bb6f50f` (`docs: prepare final ecommerce assessment submission`) on `feature/product-service-rbac-refactor`. The existing modified `compose.yaml` and untracked `docs/18-structural-integrity-audit.md` were inspected and preserved. The approved release workflow reviews and commits the refactor, integrates it into `release/ecommerce-assessment-final`, and reruns verification. It stops before pushing, merging to main, deployment, or submission.

Before behavior changes, the complete guarded Compose PostgreSQL/Redis suite passed **874 tests, 5,810 assertions, 56.62 seconds**. The development database contained zero users and zero legacy administrators before the additive migration; no development account, password, or token was created.

## Product application service

Both ProductController and AdminProductController now inject `App\Services\Product\ProductService`. Its application-facing methods are:

| Method | Contract |
|---|---|
| `listProducts(ProductQuery $query)` | Active-only public listing through ProductCatalogueCache |
| `getProduct(string $id)` | Authoritative public detail; missing/inactive returns existing 404 semantics |
| `listAdminProducts(User $actor, ProductQuery $query, ?ProductStatus $status = null)` | Policy-authorized live listing, including inactive by default |
| `getAdminProduct(User $actor, string $id)` | Policy-authorized live detail including inactive products |
| `createProduct(User $actor, array $data)` | Policy-authorized bounded initial inventory and catalogue creation |
| `updateProduct(User $actor, string $id, array $data)` | Policy-authorized partial edits and conditional inventory adjustment |

All listing methods return Laravel LengthAwarePaginator; detail/mutation methods return Product. Explicit public/admin names avoid an ambiguous visibility boolean. ProductRepositoryInterface and ProductCatalogueCache remain the only injected collaborators. ProductAdministrationService was removed only after callers/tests moved and focused product/cache checks passed; no PHP caller remains.

Mutation logic retains the field allow-list, locked current product, signed delta bounds before integer addition, normalized SKU constraints, three transaction attempts, field-specific uniqueness errors, safe contention/integrity errors, and change-only invalidation after commit. Empty/unchanged patches retain existing behavior. ProductCatalogueCache remains separate. Checkout deduction, cancellation restoration, and cart logic remain in their original services/repositories; they were not moved into ProductService.

```text
Public API → ProductController ───────────────────────┐
                                                     ↓
Admin API → Sanctum → throttle → ProductPolicy       ProductService
              → Form Request → AdminProductController   │
              → ProductService policy safeguard         ├→ ProductCatalogueCache (public list)
                                                         └→ ProductRepositoryInterface
                                                              → EloquentProductRepository
                                                              → PostgreSQL
```

## Package, guard, and cache

Composer installs **spatie/laravel-permission 8.3.0**, a stable version requiring PHP `^8.3` and Illuminate `^12.0|^13.0`, compatible with the actual PHP 8.5/Laravel 13.34 runtime. The application constraint is `^8.3`; Composer resolved the supported release normally. The package supplies standard models, assignment APIs, multiple-role permission union, cache invalidation, and Laravel authorization integration without a custom Role repository or duplicate RBAC persistence layer.

The required package configuration is published at `config/permission.php`. User uses HasRoles with a fixed `web` permission namespace, matching the existing User provider. API routes remain `auth:sanctum` with bearer-only authentication; choosing `web` for permission storage does not enable web-session API authentication. Teams, wildcard permissions, and package mutation events are disabled.

Permission metadata uses the supported Laravel `array` cache store and `spatie.permission.cache` key. It stays in the application lifecycle rather than a persistent Redis metadata cache, independently of catalogue DB 2/test 3, queue DB 4/test 5, and limiter DB 6/test 7. Bootstrap/seeding invalidate the package cache before and after changes, including failure cleanup. Supported Spatie role/permission mutations refresh package state; no global Redis flush is used.

User's central permission adapter discards loaded roles/direct-permission relations before each Spatie permission evaluation. This preserves current assignments when a direct service caller reuses an actor with stale eager-loaded relationships. Existing bearer tokens gain/lose authority when roles change without token reissuance.

`permission.register_permission_check_method=false` disables the package's broad Gate hook. AppServiceProvider defines exactly the seven namespaced InternalPermission Gates, each backed by Spatie's `checkPermissionTo`. Policies continue to call `$user->can(...)`; Spatie remains the permission resolver. Generic ownership abilities such as view/update/cancel never resolve as package permissions or incur unrelated metadata queries. This preserves the existing three-query cart loading contract and prevents even a similarly named package permission from superseding a customer ownership policy. No universal Administrator Gate bypass is added.

## Exact role and permission model

Customers have **no internal role**. Internal roles are `product_manager`, `promotion_manager`, and `administrator`. Multiple roles provide their permission union; there is no customer or combined-manager role. Administrator receives all seven defined internal permissions, not universal application authority.

| Permission | Product Manager | Promotion Manager | Administrator |
|---|---|---|---|
| `products.view-admin` | Yes | No | Yes |
| `products.create` | Yes | No | Yes |
| `products.update` | Yes | No | Yes |
| `inventory.adjust` | Yes | No | Yes |
| `promotions.view-admin` | No | Yes | Yes |
| `promotions.create` | No | Yes | Yes |
| `promotions.update` | No | Yes | Yes |

This produces 14 role-permission links. No delete, user-management, or order-management permission is added. Removing one manager role preserves the other manager's capabilities. The standard package supports direct permissions, which regression tests use to distinguish metadata updates from stock adjustment; the local provisioning commands assign canonical roles only.

```text
User ── multiple model_has_roles assignments ── Role
                                                │
                                    role_has_permissions
                                                │
                                                ↓
                                           Permission
                                                │
                         ProductPolicy / PromotionPolicy
                                                │
                                    Existing admin actions

CartPolicy / OrderPolicy ── owner ID checks for every role
```

## Authorization and API contracts

The existing eight admin endpoints retain their routes, resource shapes, status codes, and validation/business rules:

| Endpoint | Required permission |
|---|---|
| GET `/api/admin/products` | `products.view-admin` |
| GET `/api/admin/products/{id}` | `products.view-admin` |
| POST `/api/admin/products` | `products.create` |
| PATCH `/api/admin/products/{id}` | `products.update`; additionally `inventory.adjust` when `stock_adjustment` is present |
| GET `/api/admin/promotions` | `promotions.view-admin` |
| GET `/api/admin/promotions/{id}` | `promotions.view-admin` |
| POST `/api/admin/promotions` | `promotions.create` |
| PATCH `/api/admin/promotions/{id}` | `promotions.update` |

Sanctum authentication precedes named throttling, route policy authorization, Form Request validation, controller dispatch, and the service policy safeguard. ProductUpdateRequest authorizes inventory changes before validation; ProductService repeats both required policy checks for direct callers. A denied delta cannot partially change metadata. Services contain no role-name authorization branches. PromotionAdministrationService retains its actor/policy safeguards.

Missing/invalid/revoked tokens return 401; an unthrottled authenticated caller lacking permission gets 403 before validation/resource lookup. Repeated forbidden requests can receive 429 because admission precedes policy evaluation. Public catalogue remains active-only and unauthenticated. All staff still need ownership for cart, orders, checkout, and cancellation; cross-owner resource access retains non-enumerating 404 behavior.

Registration consumes only its validated customer fields. Singular/plural role/permission fields, IDs, and `is_admin` cannot grant privileges. User fillable remains name/email/password only, and serialization hides loaded roles, permissions, and the legacy flag. Normal API resources reveal no internal authorization state. No public role/permission management endpoint or default privileged account is introduced.

## Migration and compatibility flag

The historical `2026_10_06_173445_add_is_admin_to_users_table.php` remains unchanged. Two additive migrations are introduced:

1. `2026_10_07_135020_create_permission_tables.php` publishes Spatie's standard `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, and `role_has_permissions` tables, constraints, and assignment indexes.
2. `2026_10_07_135021_bootstrap_authorization.php` freezes the initial seven-permission/three-role map and backfills **only** existing `is_admin=true` users with Administrator through Spatie APIs. It preserves other assignments and is duplicate-safe.

`AuthorizationSeeder` idempotently restores canonical role permissions and invalidates metadata. It neither creates users nor repeats legacy-user backfill. Default UserFactory creates role-less customers; `administrator()`, `productManager()`, and `promotionManager()` assign roles after persistence and can be composed.

The old column remains hidden/non-fillable audit history. Spatie permissions are the **sole authorization authority**: there is no `is_admin OR permission` fallback, no flag-based limiter key, and no projection of role changes into the flag. A retained true flag without permissions is denied, including after Administrator revocation.

The data-bootstrap migration's down operation retains assignment data deliberately. Rolling it back and reapplying it runs the legacy backfill again, so a user retaining `is_admin=true` can regain Administrator even after that role was revoked. Removing the package schema loses roles, permissions, and assignments; it does not translate them into the old flag. Prefer forward fixes. Reverting to old boolean-authorizing application code could restore authority from stale historical flags and is not a safe routine rollback.

After focused verification, both migrations were applied additively to the existing local database (**22.10 ms** schema, **36.61 ms** bootstrap). The pre-migration user/legacy-admin counts were both zero, so no existing local privilege assignment was needed. `permission:show` verified guard `web`, all seven permissions, and the exact three-role matrix. No database reset or demo-user provisioning was performed. Exact schema details are in [06-database-design.md](06-database-design.md).

## Local provisioning

Use an existing account registered with a private password, with APP_ENV=local:

```bash
docker compose exec -T api php artisan roles:grant developer@example.test product_manager --no-interaction
docker compose exec -T api php artisan roles:grant developer@example.test promotion_manager --no-interaction
docker compose exec -T api php artisan roles:revoke developer@example.test product_manager --no-interaction
docker compose exec -T api php artisan admin:grant developer@example.test --no-interaction
docker compose exec -T api php artisan roles:revoke developer@example.test administrator --no-interaction
docker compose exec -T api php artisan permission:show --no-interaction
```

`admin:grant` is a compatibility alias for Administrator. RoleProvisioningService replaces AdministratorProvisioningService and uses the existing User repository for normalized lookup, a user-row lock, and supported Spatie grant/revoke APIs within a short transaction. Canonical roles and existing users are required; grant/revoke are idempotent. Every non-local environment is refused. Commands create/change no password or token. No production provisioning workflow or HTTP role-management surface is included.

The existing Admin Postman folder keeps empty credential defaults and its separate captured token. Run its complete product/promotion sequence with Administrator or both manager roles; one manager may run its own domain requests. Permission prerequisites and stable rate-limit behavior are described in the collection; credentials/tokens stay private.

## Rate limits and transaction compatibility

Every authenticated named policy now derives `user:<id>` from the current Sanctum identity instead of `admin:<id>` / `customer:<id>`. Role grant/revoke, multiple roles, token rotation, supplied request identities, and IP changes do not reset or multiply allowance. Named categories retain all thresholds and sharing behavior. Initial rollout changes old hashed identities once, so coordinate deployment to avoid mixed identities within the one-minute authenticated transition window; public/login/registration identities are unchanged. No Redis data is flushed.

The native atomic Redis admission adapter, dedicated limiter connection, 429 envelope/headers, trusted proxy handling, and sanitized fail-closed 503 remain intact. Customer/admin permission changes have no effect on queue workers or the scheduled relay.

Checkout retains Cart → ascending Products → Promotion locking, authoritative PostgreSQL pricing/status/stock, conditional deduction, snapshots/redemption/cart clear/outbox persistence, and customer-scoped idempotent replay. Cancellation retains owned Order → ascending Products locking, overflow-safe conditional restoration once, coherent persisted markers, retained promotion usage, and outbox. ProductRepository inventory primitives are unchanged.

Catalogue generation invalidation remains after commit for unified product mutations, checkout, cancellation, and inserted samples. No-op/unchanged updates, rollback, and purchase/cancellation replays do not invalidate. Public detail/admin reads remain authoritative and uncached. Outbox/queued jobs retain minimal event-ID/lease-token payloads, durable processing and retry semantics; no authorization dependency or role snapshot is added to background work.

## Historical implementation verification evidence

The following results were recorded during implementation before the independent release review. They are retained as historical evidence rather than accepted as verification of the current working tree. All database suites run sequentially against the guarded Compose PostgreSQL test database; catalogue, queue, and limiter tests retain their separate scoped Redis namespaces. Focused counts overlap the full suite and must not be added as distinct tests.

| Check | Executed result |
|---|---|
| Complete pre-change baseline | **874 passed, 5,810 assertions**, 56.62 s |
| ProductServiceTest, AdminProductControllerTest, and real Redis ProductCatalogueCacheTest | **109 passed, 921 assertions**, 17.06 s |
| Final RBAC/policies/admin/authentication/migration/provisioning/limiter coverage | **312 passed, 1,948 assertions**, 8.68 s |
| AuthorizationSeeder test after correcting its fresh-database default assertion | **7 passed, 27 assertions**, 0.57 s |
| Cart/RBAC/policy/seeder checks after scoping permission Gates | **158 passed, 645 assertions**, 4.76 s |
| Generic permission-name collision cannot bypass ownership | **1 passed, 6 assertions**, 0.44 s |
| Complete final PostgreSQL/Redis regression | **973 passed, 6,264 assertions**, 64.67 s; exit 0; **99 additional tests** over baseline |
| Seven standalone PostgreSQL/Redis contention files | **40 passed, 807 assertions**, 19.97 s; exit 0 |
| ProcessOrderEventTest, OrderOutboxServiceTest, OrderEventPersistenceTest, and OrderOutboxConcurrencyTest | **28 passed, 203 assertions**, 6.78 s; exit 0 |
| Laravel Pint | Passed; formatting corrections applied |
| Composer strict validation | Passed |
| Composer security audit | No advisories |
| Docker Compose validation | Passed; existing Compose work preserved |
| Optimized Composer autoload after the permission enum | Exit 0, **8,316 classes**; existing standalone fixture PSR-4 warning retained |
| Git whitespace / documentation links / Postman descriptions | Passed; JSON valid and only 11 description values changed |

The corrected fixture assertion had inspected the cached newly created User attribute rather than reloading the database default; it was a test setup issue, not an authorization behavior change. An initial full run also exposed two extra metadata queries before a generic ownership policy under the package's broad hook. Explicit seven-permission Gates corrected the integration; the original three-query assertion was retained, and a collision regression additionally protects ownership. Relevant cases were rerun. The pre-existing `ObservedThrottleRequests` fixture warning originates from a standalone non-PSR-4 worker class and was already present in the baseline; no new application autoload defect was identified.

Coverage includes the complete policy/role matrix, cross-domain HTTP denials, valid manager/Administrator/dual-manager use cases, registration and mass-assignment injection, stable rate identities, direct-service permission safeguards, dynamic role/direct-permission and role-permission changes, stale eager-loaded users, existing-token grant/revoke, legacy-only duplicate-safe backfill, idempotent local commands, all-staff ownership, and retained transaction/cache/concurrency guarantees. Core implementation received independent read-only review without a confirmed correctness defect.

Reproduction commands and the general isolation rules remain in [README](../README.md) and [09-testing-strategy.md](09-testing-strategy.md). No Postman request execution or production load test is claimed for this refactor.

## Independent release review — before commit

The release review independently reran checks on `feature/product-service-rbac-refactor` on October 7. An initial complete run exposed an unrelated nondeterministic cart-promotion fixture: quantities two and three used randomly generated stock as low as one. The corrected fixture explicitly supplies sufficient stock; that test-only correction is tracked separately from the 49-path refactor inventory. The subsequent complete run passed. Release-branch integration and final release verification are still pending at this checkpoint.

| Check | Independently executed result |
|---|---|
| Complete PostgreSQL/Redis regression | **973 passed, 6,264 assertions**, 72.53 s; exit 0 |
| Seven standalone concurrency files | **40 passed, 806 assertions**, 19.52 s total; every invocation exited 0 |
| CartConcurrencyTest | 3 passed, 51 assertions; 1.39 s |
| CartPromotionConcurrencyTest | 3 passed, 59 assertions; 1.35 s |
| CheckoutConcurrencyTest | 6 passed, 117 assertions; 2.28 s |
| OrderConcurrencyTest | 6 passed, 116 assertions; 2.29 s |
| AdminConcurrencyTest | 16 passed, 288 assertions; 8.99 s |
| OrderOutboxConcurrencyTest | 2 passed, 27 assertions; 1.07 s |
| RateLimitConcurrencyTest | 4 passed, 148 assertions; 2.15 s |
| ProductServiceTest + AdminProductControllerTest | **59 passed, 333 assertions**, 2.08 s; exit 0 |
| RbacAuthorizationTest + AdminAuthorizationTest + ProductPolicyTest + PromotionPolicyTest + AdministratorProvisioningServiceTest | **147 passed, 587 assertions**, 4.33 s; exit 0 |
| AuthorizationMigrationTest + UserAdministratorMigrationTest + AuthorizationSeederTest | **11 passed, 56 assertions**, 0.94 s; exit 0 |
| Real Redis ProductCatalogueCacheTest | **50 passed, 588 assertions**, 15.74 s; exit 0 |
| ProcessOrderEventTest + OrderOutboxServiceTest + OrderEventPersistenceTest + OrderOutboxConcurrencyTest | **28 passed, 203 assertions**, 6.30 s; exit 0 |
| ThrottleApiRequestsTest + RbacRateLimitingTest | **52 passed, 725 assertions**, 1.51 s; exit 0 |
| Laravel Pint (`--dirty --format agent`) | Passed |
| Composer strict validation / audit | Valid; no security vulnerability advisories |
| Docker Compose / Git whitespace | Passed |
| Optimized Composer autoload | Exit 0, **8,316 classes**; existing standalone fixture PSR-4 warning still emitted |
| Documentation relative links / Postman JSON | No missing links; JSON valid; all 11 changed values are descriptions |

The newly executed standalone assertion total is 806; the historical implementation total was 807. Both totals are preserved as observations of their respective runs. Focused groups overlap the complete suite and are not additional distinct tests. Final release-branch results require their own invocations and are not inferred from this checkpoint.

## Remaining limits

- **Critical/High:** no confirmed outstanding defect from independent source review and passing focused/complete regression.
- **Medium:** schema rollback loses RBAC assignments; bootstrap rollback/reapplication can regrant revoked legacy administrators, and old-code rollback can revive historical flags. Use forward fixes and preserve data.
- **Medium:** the initial limiter-key rollout resets existing authenticated one-minute counters once; subsequent role changes preserve quotas. Coordinate rollout rather than clearing Redis.
- **Low:** local-only provisioning does not provide a production operator workflow. Tokens retain the existing indefinite/wildcard-ability assessment policy while privileges resolve from current permissions.
- **Low:** the existing standalone fixture PSR-4 warning remains; it is separated from successful application optimized autoload and tests.

No universal Administrator bypass, customer role, additional admin API, privilege-management frontend, stock reservation, checkout/cancellation redesign, or Redis/queue redesign is part of this change.

## Changed-file inventory

The refactor inventory contains **49 paths**: 33 modified tracked files, two removed tracked production services, and 14 new files. The two additional existing working-tree paths (`compose.yaml` and `docs/18-structural-integrity-audit.md`) are preserved user work, not refactor changes.

Application code (18 paths):

```text
app/Console/Commands/GrantAdministrator.php
app/Console/Commands/GrantRole.php                         new
app/Console/Commands/RevokeRole.php                        new
app/Contracts/Repositories/UserRepositoryInterface.php
app/Enums/InternalPermission.php                          new
app/Enums/InternalRole.php                                new
app/Http/Controllers/Api/AdminProductController.php
app/Http/RateLimiting/ApiRateLimiters.php
app/Http/Requests/Admin/ProductUpdateRequest.php
app/Models/User.php
app/Policies/ProductPolicy.php
app/Policies/PromotionPolicy.php
app/Providers/AppServiceProvider.php
app/Repositories/Eloquent/EloquentUserRepository.php
app/Services/Auth/AdministratorProvisioningService.php     removed
app/Services/Auth/RoleProvisioningService.php              new
app/Services/Product/ProductAdministrationService.php     removed
app/Services/Product/ProductService.php
```

Dependency/configuration and database changes (seven paths):

```text
composer.json
composer.lock
config/permission.php                                     new
database/factories/UserFactory.php
database/migrations/2026_10_07_135020_create_permission_tables.php  new
database/migrations/2026_10_07_135021_bootstrap_authorization.php   new
database/seeders/AuthorizationSeeder.php                   new
```

Tests (ten paths):

```text
tests/Feature/Database/Seeders/AuthorizationSeederTest.php  new
tests/Feature/Http/Controllers/Api/AdminAuthorizationTest.php
tests/Feature/Http/Controllers/Api/RbacAuthorizationTest.php  new
tests/Feature/Http/Middleware/RbacRateLimitingTest.php      new
tests/Feature/Models/AuthorizationMigrationTest.php        new
tests/Feature/Policies/ProductPolicyTest.php
tests/Feature/Policies/PromotionPolicyTest.php
tests/Feature/Services/Admin/AdminConcurrencyTest.php
tests/Feature/Services/Auth/AdministratorProvisioningServiceTest.php
tests/Feature/Services/Product/ProductCatalogueCacheTest.php
```

The existing provisioning test filename is retained while its cases now cover RoleProvisioningService and all local commands; it is not a production class reference. Existing test files are retained and prior behavior regressions remain covered.

Documentation and Postman (14 paths):

```text
README.md
app/Services/README.md
docs/05-architecture.md
docs/06-database-design.md
docs/07-api-contracts.md
docs/08-business-rules.md
docs/09-testing-strategy.md
docs/10-implementation-roadmap.md
docs/14-admin-management.md
docs/15-redis-caching.md
docs/17-api-rate-limiting.md
docs/18-final-submission-review.md
docs/19-product-service-rbac-refactor.md                    new
postman/Ecommerce_Order_Promotion_API.postman_collection.json
```

Earlier evidence tables remain historical. Postman changes are confined to 11 descriptions; request methods, URLs, payloads, scripts, authentication, and variables are unchanged.
