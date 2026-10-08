# 18 — Structural integrity and architecture audit

Review date: **October 7, 2026** (Africa/Cairo).

## Superseding release status — October 8, 2026

**Historical scope:** the executive conclusion and sections 1–12 below record the original audit at `bb6f50f`. Their class names, recommendations, warning, test counts, and Git status are evidence from that checkout, not descriptions of the final release. Historical checkout paths and temporary observation commands are not reviewer setup requirements; use [README](../README.md) for reproducible setup.

Later release commits supersede the following findings:

- `81cbffa` unified public and administrative product use cases in `ProductService`, removed `ProductAdministrationService`, and introduced Spatie Laravel Permission 8.3.0. Three internal roles use seven permissions; customers remain role-less. The retained `is_admin` column grants no authority, and there is no global Administrator bypass of ownership policies. `RoleProvisioningService` replaces `AdministratorProvisioningService`; see [the RBAC report](19-product-service-rbac-refactor.md).
- `16faf77` added [seven immutable application-input DTOs](../app/DTOs/README.md) for authentication and product/promotion administration. Repository interfaces, monetary units, and transaction/locking protocols remain unchanged.
- `3043567` extracted `Tests\Fixtures\ObservedThrottleRequests` into [its matching PSR-4 file](../tests/Fixtures/ObservedThrottleRequests.php). The historical Composer warning is resolved; the child-process fixture imports the helper.

The bearer-only Sanctum, database-backed web sessions, integer `_minor` money, repository bindings, Redis separation, and transactional outbox conclusions remain applicable. Unlimited token lifetime is an existing assessment policy; production lifecycle decisions and the absence of static analysis remain documented limitations. API money-shape wording has been clarified in [the contracts](07-api-contracts.md).

The final Docker review retains optional PostgreSQL host access on a configurable loopback-only port and runs the API with `artisan serve --no-reload` to preserve Compose's internal database/Redis environment overrides. Neither correction changes the existing `.env`, persistent database volume, test isolation, or unrelated containers. Restart the API after environment edits as documented in [README](../README.md).

Final review verification on the release branch based on `3043567`, after the Docker corrections on October 8, passed **1,171 tests / 6,629 assertions** and **40 standalone concurrency tests / 806 assertions**. Pint, Composer strict validation/audit, strict PSR-4/ambiguity checks, optimized autoload (**8,324 classes**), Compose validation, and Git whitespace checks passed without warnings. These later results do not replace the original audit evidence below; assertion totals can vary by the permitted cancellation/checkout race outcome.

## Executive conclusion (historical)

**No confirmed Critical or High code defect was found.** The suspected `App\Services\Product\ProductAdministrationService` exists, is committed on the audited branch, resolves through Laravel's container, and is exercised through real admin HTTP requests. No missing application classes, incorrect production namespaces, duplicate declarations, broken repository bindings, or missing route actions were found.

The audited checkout was safe to continue toward final submission against its recorded requirements. This is a structural audit of that repository/runtime, not a production deployment approval or verification of wording in the unavailable original employer brief.

Keep the integer `_minor` money design and lightweight `is_admin` authorization. Keep `sessions` with the present web routes and database session configuration. No production code, tests, migrations, dependencies, or existing documentation were changed. This report is the only new repository file.

## 1. Preflight and preservation

| Item | Observed value |
| --- | --- |
| Historical checkout path (not a setup requirement) | `/var/www/ecommerce-order-api` |
| Branch | `release/ecommerce-assessment-final` |
| HEAD | `bb6f50fd2bd467707d7d8087e7e3c4ce23d9f818` |
| Initial Git status | ` M compose.yaml` — pre-existing, unstaged change |
| Host PHP | 8.5.4, 64-bit |
| API container PHP | 8.5.11, 64-bit |
| Laravel | 13.34.0 |
| Sanctum | 4.3.3 |
| Pest / PHPUnit | 4.7.8 / 12.5.33 |
| PostgreSQL | 17.11, project Compose service `postgres` |
| Composer, host / container | 2.9.5 / 2.10.3 |
| Optimized autoload | Exit 0; 8,238 classes; one standalone fixture warning described below |

`composer show --direct` and Boost application information confirmed installed versions. `.ai/rules` does not exist. Project AGENTS.md and the Laravel best-practices and testing best-practices skills were read. Version-specific Boost documentation was consulted for session storage, authentication, and dependency injection.

The branch and HEAD remain unchanged. The existing `compose.yaml` edit was preserved, including a byte-hash preservation check. No commit, push, merge, reset, dependency installation, or change to `postgres-local` occurred. All database tests ran sequentially against the guarded `ecommerce_order_api_test` database on the project `postgres` service. Development database inspection used read-only queries.

## 2. Scope and verification method

The scan and syntax audit covered **220 application-owned PHP files**: 113 in `app/`, 3 in `routes/`, 15 in `config/`, 30 in `database/` (all 17 migrations, 10 factories, and 3 seeders), 54 in `tests/`, 2 bootstrap source files, `public/index.php`, the welcome Blade file, and the `artisan` entry point. Generated bootstrap caches and vendor source were excluded. Review also covered `.env.example`, a safe allow-list of current environment settings, Composer/PHPUnit/Compose configuration, README, architecture/database/API/business rules, and the relevant milestone/audit reports.

An authorized temporary inspection script used the already-installed `nikic/php-parser` 5.9.0. It parsed all 220 files, resolved namespaces, checked **130 named declarations, 1,114 imports, and 2,772 class-name references**, and checked literal application class strings, declaration uniqueness, PSR-4 file paths, attributes, inheritance, traits, type hints, construction, static references, and catches. No unused import candidate was found. Extension-dependent results were rerun inside the actual API container.

A separate temporary application-context inspection booted Laravel and resolved every repository contract, all 15 services, 4 application commands, the listener, 2 custom middleware classes, 9 API controllers, and the 4 discovered policies. For all 25 API entries it checked the controller action, request types, constructor dependencies, and expanded middleware. Form Requests were instantiated without invoking their authorization/validation callbacks on an empty CLI request; HTTP behavior was verified by existing tests. DTOs, enums, events, and jobs with required payload arguments were checked for valid declarations/types rather than fabricated container arguments. The job's processing service and command method dependencies resolve.

These checks address class existence and wiring beyond passing tests. They are not equivalent to a full PHPStan type analysis and do not prove every dynamically generated value or every possible future code path.

Boost's schema tool returned an empty table list and its read-only query failed with `could not find driver` in the host context. Actual schema and session counts were therefore checked using read-only `psql` queries in the existing project PostgreSQL container. No development database or container was reset; existing tests refresh only their guarded test database.

## 3. Authentication and sessions — Q1

The table originates in Laravel's standard `database/migrations/0001_01_01_000000_create_users_table.php:30`. Its presence does not mean Sanctum SPA authentication is enabled.

| Question | Verified answer and evidence |
| --- | --- |
| Personal-access bearer tokens? | **Yes.** `AuthService::register()` and `login()` call `User::createToken()`; `AuthController` returns `token_type: Bearer`; protected routes use `auth:sanctum`. `User` uses `HasApiTokens`. Tokens are stored hashed in `personal_access_tokens`; logout deletes only the current token. |
| Cookie/session Sanctum SPA authentication enabled? | **No for the API.** `config/sanctum.php:40` sets `guard` to `[]`; `bootstrap/app.php` does not enable `statefulApi()`. No API entry expands to `EnsureFrontendRequestsAreStateful` or session middleware. Existing HTTP tests reject web-session-only customers and administrators. |
| `SESSION_DRIVER=database`? | **Yes in the example and current local runtime**, confirmed in both host and container configuration. `config/session.php:21` defaults to database. **Tests use `array`**, as configured in `phpunit.xml:52`. |
| Production application code explicitly uses sessions? | No explicit application `session()`, session-facade call, session-based login, or session persistence was found in `app/`, routes, or bootstrap source. **Framework web middleware uses sessions indirectly** for the root welcome route and Sanctum's CSRF-cookie route. |
| Any `/api/*` route loads session middleware? | **No.** All 25 expanded API middleware stacks were inspected. |
| Session rows written by authentication tests? | **No.** All 50 existing authentication cases were rerun with temporary query observation. Every case used the array session driver, had zero session rows before/after, and emitted zero SQL writes to `sessions`. The run passed with its original 261 assertions. |
| Is the table necessary? | **Required for the current application's database-backed web middleware; unnecessary for bearer-token API authentication itself.** |

`/sanctum/csrf-cookie` remains registered by Sanctum's provider. Its expanded middleware includes `StartSession`, cookie encryption, shared session errors, and request-forgery protection. `routes/web.php:5` also uses the web group. The CSRF-cookie route is an available framework endpoint, not a configured SPA login flow. `config/sanctum.php` retaining stateful-domain defaults and SPA middleware configuration entries does not activate them on API requests.

**Classification: Required under the current whole-application configuration. Recommendation: keep it.** Removing the table alone can break the existing web/CSRF-cookie endpoints. If an explicitly authorized future cleanup removes or changes those consumers and chooses another session strategy, the schema can be reconsidered then. The development table contained **0 rows before and after this audit**; emptiness alone is not proof of safe removal.

No README or project documentation incorrectly calls current bearer-token authentication “Sanctum SPA authentication.” `docs/07-api-contracts.md:80`, `docs/05-architecture.md:128`, and `docs/13-final-audit.md:160` explicitly describe bearer-only authentication. Sanctum's stock configuration comments describe optional framework functionality, not this application's enabled flow.

## 4. Monetary representation — Q2

**`_minor` means an integer count of the configured currency's minor units.** For USD, 1,899 means USD 18.99. Currency labels do not perform currency conversion. The deployment has one catalogue currency; orders store its purchase-time value. No monetary persistence or calculation using PHP float/double was found.

Actual PostgreSQL columns match the migrations:

| Table | Monetary / percentage fields | Storage and interpretation |
| --- | --- | --- |
| `products` | `price_minor` | bigint; nonnegative minor units |
| `promotions` | `minimum_cart_amount_minor`, `maximum_discount_minor` | bigint; nonnegative minimum, nullable positive cap |
| `promotions` | `value` | bigint; fixed promotion = minor units, percentage promotion = basis points, 1..10,000 |
| `promotion_redemptions` | `discount_minor` | bigint; nonnegative historical applied discount |
| `orders` | `subtotal_minor`, `discount_minor`, `total_minor` | bigint; nonnegative, discount <= subtotal, total = subtotal - discount |
| `orders` | `promotion_value_snapshot` | bigint; historical type-dependent minor units / basis points |
| `orders` | `promotion_maximum_discount_minor_snapshot` | nullable positive bigint; purchase-time cap |
| `order_items` | `unit_price_minor`, `line_subtotal_minor` | bigint; nonnegative, exact quantity × unit price |

`CartPricingService::summarize()` checks multiplication using `intdiv(PHP_INT_MAX, quantity)` and accumulation using subtraction before arithmetic can overflow to float. Checkout uses this same service with locked, current products, then `EloquentOrderRepository::create()` / `createItems()` persist those exact values and snapshots. Order reads use persisted values, not live catalogue prices. Promotion redemptions copy the order's discount.

`PromotionCalculator::calculate()` uses quotient/remainder integer arithmetic for percentage discounts, deterministic half-up rounding, and a cap/subtotal clamp. Percentages are basis points: 2,000 = 20%, 10,000 = 100%. Its decomposition avoids `subtotal * basisPoints` overflow. PostgreSQL's `::numeric` multiplication in the order-item CHECK constraint is exact decimal arithmetic used to avoid bigint overflow in validation; the stored amounts remain bigint, with no floating-point money.

Models cast monetary attributes to integer; DTO fields are typed integers. Product/cart/order total resources emit integer `amount_minor` values with currency. Historical order items expose explicit `unit_price_minor` / `line_subtotal_minor` integers under the order's currency. Admin promotions expose editable minor-unit inputs directly; customer promotion resources distinguish fixed money from percentage basis points. No resource divides stored amounts into floating-point major units.

The JSON **shape is deliberately not uniform** across totals, historical item fields, and admin inputs, but monetary units and values are consistent. The general money-object statement in `docs/07-api-contracts.md:8` could more explicitly name these documented exceptions (LOW documentation finding). Generic `value` and `promotion_value_snapshot` are appropriately named because they also represent percentages; the cap's `_minor_snapshot` naming follows the snapshot family. Neither warrants a schema rename.

The decision and exceptions are documented in `docs/06-database-design.md:8`, `docs/07-api-contracts.md:121`, its checkout/admin examples, and `docs/08-business-rules.md:43`. Clients must preserve large integer JSON values beyond IEEE-754's exact range; this existing documented boundary is not a reason to introduce floating-point amounts. **Recommendation: retain the current money design.**

## 5. Customer and administrator authorization — Q3

`users.is_admin` is a non-null boolean with database default `false`, added by `2026_10_06_173445_add_is_admin_to_users_table.php`. `User::casts()` treats it as boolean. `User` permits mass assignment only of name/email/password and hides the admin flag from serialization (`app/Models/User.php:18`). Registration validates only customer fields and `AuthService::register()` explicitly constructs that same customer allow-list.

All eight admin routes require Sanctum authentication and a server-side `can` ability for Product or Promotion (`routes/api.php:16`). Auto-discovered `ProductPolicy` and `PromotionPolicy` grant only when the authenticated, stored user's flag is strictly true. Admin Form Requests repeat policy authorization and both administration services call `Gate::forUser()->authorize()` for reads and writes. No separate custom “admin middleware” is needed; Laravel's resolved `Authorize` middleware supplies that boundary.

Wildcard token abilities, body/query `is_admin`, role/permission fields, and a web login do not grant administrator privileges. Existing tests cover all admin routes for missing/invalid/revoked tokens and customer denial, forged registration, mass assignment, direct service authorization, and removal of the stored privilege while a token remains valid. Customers cannot escalate through any implemented HTTP endpoint. Operators with database/server access are a separate trusted boundary.

The factory's `administrator()` state is test setup. The default seeder creates an ordinary user. `AdministratorProvisioningService::grantAdministrator()` requires `local`, finds an existing account, and delegates to `UserRepositoryInterface`; its CLI command refuses other environments. There is no HTTP grant/revoke endpoint.

Cart and order access remains owner-scoped through repositories and policies, returning non-enumerating 404 denials. Being an administrator does not override these customer ownership checks.

**Recommendation: keep lightweight `is_admin`; defer RBAC as a future extension.** Current recorded requirements need only customer ownership and one administrator capability set for products/promotions. Roles/permission tables would add no demonstrated acceptance value. Introduce granular roles only if requirements add independently assignable permissions or multiple operator roles. No Spatie package or RBAC tables were added. Production administrator provisioning remains a documented future operator workflow, not an assessment blocker.

## 6. ProductAdministrationService and class references — Q4

The exact class exists at `app/Services/Product/ProductAdministrationService.php:22`, with namespace `App\Services\Product` and constructor dependencies `ProductRepositoryInterface` and `ProductCatalogueCache` at line 26. It was present at initial inspection and is tracked in HEAD. Its last file-changing commit is `c11fee5` (`feat: add Redis product catalogue caching and invalidation`).

Every initial repository file referencing that name is listed below. This report adds further explanatory references.

| File | Lines / symbols | Use |
| --- | --- | --- |
| `app/Services/Product/ProductAdministrationService.php` | 22 | Actual declaration; namespace at line 3 |
| `app/Http/Controllers/Api/AdminProductController.php` | 10, 17 | Import and constructor injection; index/show/store/update call the service |
| `tests/Feature/Http/Controllers/Api/AdminAuthorizationTest.php` | 4, 93 | Import; direct service authorization dataset |
| `tests/Feature/Services/Product/ProductCatalogueCacheTest.php` | 13, 176, 188, 210, 223, 267, 268, 288, 305 | Import; real container calls for creation, mutation, invalidation, no-op, rollback and race coverage |
| `docs/05-architecture.md` | 207, 225 | Architecture and invalidation descriptions |
| `docs/14-admin-management.md` | 58 | Controller → service → repository diagram |
| `docs/15-redis-caching.md` | 240 | Implementation file list |

There is **no explicit binding for ProductAdministrationService**, and none is required: Laravel automatically builds concrete classes. `AppServiceProvider::register()` binds its repository contract to `EloquentProductRepository` and scopes `ProductCatalogueCache`. All three resolve successfully.

`GET/POST /api/admin/products` and `GET/PATCH /api/admin/products/{id}` execute its controller. `AdminProductControllerTest.php` exercises real list/detail/create/update requests and PostgreSQL writes without replacing this service with a mock. Admin authorization, catalogue cache, and independent-process admin concurrency tests also exercise it. **Tests passed because the class exists and the exercised dependency graph is valid**, not because the import is unused or its route is skipped. Only the audited checkout's contents are verified here; no earlier checkout was available for comparison.

### All unresolved-name scan results, reconciled

| Reference and location | Explanation | Classification / action |
| --- | --- | --- |
| `App\Services\Product\ProductAdministrationService` | Exists, correct namespace/file, container resolves, route and direct tests execute it | NOT A DEFECT; retain |
| Other project classes/interfaces under `App\`, factory/seeder namespaces | All imported/referenced declarations resolve; no missing implementation, duplicate, stale import, namespace/file mismatch, or literal missing application class string found | NOT A DEFECT; no corrections |
| `Pdo\Mysql`, `config/database.php:4`, `:63`, `:83` | MySQL extension class absent from this PostgreSQL container; both constant uses are inside `extension_loaded('pdo_mysql') ? ... : []`, so the absent class is never evaluated here | NOT A DEFECT; guarded optional database configuration |
| `RedisException`, host-only hits in `ThrottleApiRequests.php:8,25` and `ProductCatalogueCache.php:17,76,95,135`; `Redis` / `RedisException` in related tests | Host PHP lacks PhpRedis; these extension classes exist in the intended API container and their HTTP/cache/worker paths pass there | LOW environment limitation, not a missing project class; use documented container runtime |
| `ObservedThrottleRequests`, `tests/Fixtures/rate-limit-request.php:23,40` | Global helper declared inside a directly executed child-process fixture; absent from the Composer classmap by design; runtime declaration precedes middleware alias registration | LOW autoload warning; optional future extraction to a PSR-4 test support class |

The fixture causes Composer's message: `Class ObservedThrottleRequests located in ./tests/Fixtures/rate-limit-request.php does not comply with psr-4 autoloading standard (rule: Tests\ => ./tests). Skipping.` Optimized generation still exits 0. `RateLimitConcurrencyTest.php` runs the fixture by PHP filename; it does not request the helper through Composer. Its real subprocess tests pass. No fixture restructuring was necessary for a Critical/High correction.

Neither PHPStan nor Larastan is installed, and no analyzer configuration/executable exists. Textual occurrences in Composer metadata include dependencies' own development suggestions and `phpstan/phpdoc-parser`; these do not constitute an installed analyzer. No new dependency was installed. A compatible analyzer is a reasonable separately scoped future tooling improvement, not justification for speculative class creation or architecture changes.

## 7. Repository and framework abstraction resolution

Repository names below use `App\Contracts\Repositories`; implementations use `App\Repositories\Eloquent`. The binding source is `app/Providers/AppServiceProvider.php::register()`.

| Interface | Bound implementation | Exists | Resolves |
| --- | --- | --- | --- |
| CartRepositoryInterface | EloquentCartRepository | Yes | Yes; implements contract |
| ProductRepositoryInterface | EloquentProductRepository | Yes | Yes; implements contract |
| PromotionRepositoryInterface | EloquentPromotionRepository | Yes | Yes; implements contract |
| OrderRepositoryInterface | EloquentOrderRepository | Yes | Yes; implements contract |
| OrderOutboxRepositoryInterface | EloquentOrderOutboxRepository | Yes | Yes; implements contract |
| UserRepositoryInterface | EloquentUserRepository | Yes | Yes; implements contract |
| Illuminate\Contracts\Cache\Factory | Illuminate\Cache\CacheManager | Yes | Yes |
| Illuminate\Contracts\Queue\Factory | Illuminate\Queue\QueueManager | Yes | Yes |
| Illuminate\Contracts\Events\Dispatcher | Illuminate\Events\Dispatcher | Yes | Yes |
| Psr\Log\LoggerInterface | Illuminate\Log\LogManager | Yes | Yes |

`ProductCatalogueCache` resolves through its explicit scoped registration. Other application services use concrete auto-resolution with the above contracts; no separate service interface is declared without an implementation. Policy discovery resolves CartPolicy, OrderPolicy, ProductPolicy, and PromotionPolicy.

## 8. Service / Repository integrity matrix

Controller names are under `App\Http\Controllers\Api`, services under the indicated domain in `App\Services`, repository contracts/implementations as in section 7. All listed layers exist and resolve.

| Domain | Controller / entry point | Service | Repository interface | Repository implementation | Status |
| --- | --- | --- | --- | --- | --- |
| Authentication | AuthController | Auth/AuthService | UserRepositoryInterface | EloquentUserRepository | Intact; native Sanctum token methods are a documented exception |
| Administrator provisioning | GrantAdministrator command | Auth/AdministratorProvisioningService | UserRepositoryInterface | EloquentUserRepository | Intact; local-only CLI use case |
| Products | ProductController | Product/ProductService | ProductRepositoryInterface | EloquentProductRepository | Intact |
| Product administration | AdminProductController | Product/ProductAdministrationService | ProductRepositoryInterface | EloquentProductRepository | Intact; shared repository is appropriate |
| Cart | CartController | Cart/CartService, Cart/CartPricingService | CartRepositoryInterface, ProductRepositoryInterface | EloquentCartRepository, EloquentProductRepository | Intact; pricing also composes promotion services |
| Promotions in cart | CartPromotionController | Cart/CartPromotionService, Promotion/PromotionService, Promotion/PromotionCalculator | CartRepositoryInterface, PromotionRepositoryInterface | EloquentCartRepository, EloquentPromotionRepository | Intact; calculator is pure logic and needs no repository |
| Promotion administration | AdminPromotionController | Promotion/PromotionAdministrationService | PromotionRepositoryInterface | EloquentPromotionRepository | Intact; shared promotion lock/ledger semantics |
| Checkout | CheckoutController | Checkout/CheckoutService; shared pricing/outbox/cache services | Cart, Product, Promotion, Order, OrderOutbox repository interfaces | Corresponding five Eloquent repositories | Intact; service owns one business transaction |
| Orders | OrderController | Order/OrderService | OrderRepositoryInterface | EloquentOrderRepository | Intact; owner-scoped reads |
| Cancellation | OrderController::cancel | Order/OrderService; OrderOutboxService | OrderRepositoryInterface, ProductRepositoryInterface, OrderOutboxRepositoryInterface | EloquentOrderRepository, EloquentProductRepository, EloquentOrderOutboxRepository | Intact; atomic status/restoration/event outcome |
| Outbox/events | DispatchOrderOutbox, InspectOrderOutbox, RetryOrderOutbox commands; ProcessOrderEvent job; RecordOrderNotification listener | Order/OrderOutboxService, Order/OrderEventProcessingService | OrderOutboxRepositoryInterface; native queue/event contracts | EloquentOrderOutboxRepository; framework managers/dispatcher | Intact; no artificial HTTP controller required |
| Catalogue caching | ProductController through ProductService | Product/ProductCatalogueCache | ProductRepositoryInterface through loader; native cache factory | EloquentProductRepository; CacheManager | Intact; cache component is not a separate persistence domain |
| Health | HealthController | No business service needed | None needed | None needed | Intentional simple response |

Controllers contain no database queries or business arithmetic. Services coordinate transactions and contracts; repositories implement queries, row locks, and writes. Framework integrations, typed model state/relations, pure calculators, and the simple health response do not justify symmetrical extra repositories or service classes.

## 9. Runtime API route audit

All 25 API entries passed existence, public action, dependency, request-type, and expanded middleware checks. No API stack contains session or stateful SPA middleware. Every entry has its configured named limiter. **All eight admin entries require `auth:sanctum` plus the indicated discovered policy ability**. Other protected entries use bearer authentication plus owner-scoped service/repository authorization.

The detailed route/request matrix follows. `Request` means Laravel's ordinary HTTP Request; a dash means no request parameter. HEAD shares the GET action. Every row's controller and request types exist and the dependencies resolve.

| Method | API path | Controller action | Request type | Authentication / route policy |
| --- | --- | --- | --- | --- |
| GET / HEAD | `/api/admin/products` | `AdminProductController::index` | `Admin\ProductQueryRequest` | `auth:sanctum; viewAny,Product` |
| GET / HEAD | `/api/admin/products/{id}` | `AdminProductController::show` | `Request` | `auth:sanctum; view,Product` |
| POST | `/api/admin/products` | `AdminProductController::store` | `Admin\ProductStoreRequest` | `auth:sanctum; create,Product` |
| PATCH | `/api/admin/products/{id}` | `AdminProductController::update` | `Admin\ProductUpdateRequest` | `auth:sanctum; update,Product` |
| GET / HEAD | `/api/admin/promotions` | `AdminPromotionController::index` | `Admin\PromotionQueryRequest` | `auth:sanctum; viewAny,Promotion` |
| GET / HEAD | `/api/admin/promotions/{id}` | `AdminPromotionController::show` | `Request` | `auth:sanctum; view,Promotion` |
| POST | `/api/admin/promotions` | `AdminPromotionController::store` | `Admin\PromotionStoreRequest` | `auth:sanctum; create,Promotion` |
| PATCH | `/api/admin/promotions/{id}` | `AdminPromotionController::update` | `Admin\PromotionUpdateRequest` | `auth:sanctum; update,Promotion` |
| GET / HEAD | `/api/health` | `HealthController::__invoke` | `—` | `Public` |
| POST | `/api/checkout` | `CheckoutController::__invoke` | `Checkout\CheckoutRequest` | `auth:sanctum` |
| GET / HEAD | `/api/orders` | `OrderController::index` | `Order\OrderQueryRequest` | `auth:sanctum` |
| GET / HEAD | `/api/orders/{id}` | `OrderController::show` | `Request` | `auth:sanctum` |
| POST | `/api/orders/{id}/cancel` | `OrderController::cancel` | `Request` | `auth:sanctum` |
| GET / HEAD | `/api/products` | `ProductController::index` | `Product\ProductQueryRequest` | `Public` |
| GET / HEAD | `/api/products/{id}` | `ProductController::show` | `—` | `Public` |
| GET / HEAD | `/api/cart` | `CartController::show` | `Request` | `auth:sanctum` |
| POST | `/api/cart/items` | `CartController::store` | `Cart\AddCartItemRequest` | `auth:sanctum` |
| PATCH | `/api/cart/items/{id}` | `CartController::update` | `Cart\UpdateCartItemRequest` | `auth:sanctum` |
| DELETE | `/api/cart/items/{id}` | `CartController::destroy` | `Request` | `auth:sanctum` |
| POST | `/api/cart/promotion` | `CartPromotionController::store` | `Cart\ApplyPromotionRequest` | `auth:sanctum` |
| DELETE | `/api/cart/promotion` | `CartPromotionController::destroy` | `Request` | `auth:sanctum` |
| POST | `/api/auth/register` | `AuthController::register` | `Auth\RegisterRequest` | `Public` |
| POST | `/api/auth/login` | `AuthController::login` | `Auth\LoginRequest` | `Public` |
| POST | `/api/auth/logout` | `AuthController::logout` | `Request` | `auth:sanctum` |
| GET / HEAD | `/api/auth/me` | `AuthController::me` | `Request` | `auth:sanctum` |

## 10. Findings and recommended actions

| Severity | File / symbol | Evidence and significance | Recommended action |
| --- | --- | --- | --- |
| CRITICAL | Production source and audited dependency graph | No confirmed Critical defect | No code correction |
| HIGH | API controllers, repositories, money/auth boundaries | No confirmed High defect | No code correction |
| MEDIUM | `config/sanctum.php:53`; AuthService token issuance | Global expiration is null and issuance uses native no-expiration / wildcard-ability defaults. This matches the recorded assessment contract but leaves token lifetime unrestricted | Retain assessment behavior; separately define production token lifecycle if required |
| LOW | `tests/Fixtures/rate-limit-request.php:23` | Composer warns about the directly executed global helper's PSR-4 mismatch; runtime tests execute it successfully | Optional focused fixture extraction; avoid broad changes in this audit |
| LOW | Host PHP runtime; `config/database.php` / Redis integration | Host lacks PostgreSQL PDO and PhpRedis; host `schedule:list` and Boost DB query cannot use the configured services | Run database/Redis checks in the existing API container, as done here |
| LOW | `docs/07-api-contracts.md:8`; OrderItemResource / AdminPromotionResource | General money-object wording has documented raw integer exceptions; units are correct but presentation is not uniform | Clarify that sentence in a future documentation cleanup; preserve current API contracts |
| NOT A DEFECT | User migration `sessions`; web and CSRF-cookie routes | No bearer API dependence or authentication-test writes; current web middleware requires configured session persistence | Keep the table under current configuration |
| NOT A DEFECT | All monetary columns/calculations | Bigint persistence, typed integer DTOs/casts, exact bounded calculations and shared checkout/cart pricing | Keep `_minor` design |
| NOT A DEFECT | User flag, policies, admin route middleware | Server-side stored privilege; no registration/mass-assignment escalation; all admin routes protected | Keep `is_admin`; defer RBAC |
| NOT A DEFECT | ProductAdministrationService and other project references | Real tracked declaration; all project names/bindings/actions valid; concrete auto-resolution works | No missing-class fix |
| NOT A DEFECT | `config/database.php:4,63,83` | Optional MySQL extension reference guarded before evaluation | No change for PostgreSQL deployment |

Absence of PHPStan/Larastan is a verification limitation, not a discovered code defect. No analyzer was silently installed and no claim of a clean PHPStan run is made. Other previously documented deployment topics were not broadly refactored to expand this audit's scope.

## 11. Verification results

| Check | Exact result |
| --- | --- |
| `composer dump-autoload -o` | Exit 0; 8,238 optimized classes; one fixture PSR-4 warning |
| `php artisan about --no-interaction` | Exit 0 on host and API container; versions/configuration confirmed |
| `php artisan route:list --no-interaction` | Exit 0 on host and API container; 31 total framework/application entries, 25 API entries |
| `php artisan event:list --no-interaction` | Exit 0 on host and API container; OrderPlaced/OrderCancelled both map to RecordOrderNotification::handle |
| Host `php artisan schedule:list --no-interaction` | Exit 1: missing PostgreSQL PDO driver; environment limitation |
| API container `php artisan schedule:list --no-interaction` | Exit 0; orders:dispatch-outbox every 10 seconds |
| PHP syntax audit, all 220 owned files | All passed; 0 syntax failures |
| Parsed class/reference inspection | No missing project class, duplicate, production PSR-4 mismatch, or unused import candidate; reconciled extension/fixture hits in section 6 |
| Container inspection | All 6 repository contracts, 4 framework abstractions, 15 services, 4 commands, listener, custom middleware, 4 policies and 25 API entries valid |
| Complete current suite | **874 passed, 5,810 assertions, 48.33 seconds; exit 0** |
| Standalone contention/integration set | **40 passed, 806 assertions, 15.17 seconds; exit 0** |
| Existing authentication suite with temporary session query observation | **50 passed, 261 assertions, 1.24 seconds; exit 0**; array driver; 0 rows before/after every case; 0 session writes |
| Development sessions snapshot | 0 rows before and after verification |
| Git whitespace check | Passed |

No failing, warning, or skipped tests were reported. Repeated focused runs overlap the full suite; their counts must not be added as distinct tests. The fixture warning belongs to Composer autoload generation, not the test results.

Commands for the executed test runs:

```bash
docker compose exec -T api php artisan test --compact

docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php \
  tests/Feature/Services/Order/OrderOutboxConcurrencyTest.php \
  tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php

docker compose exec -T api php artisan test --compact \
  --bootstrap=/tmp/structural-auth-bootstrap.php \
  tests/Feature/Http/Controllers/Api/AuthControllerTest.php
```

The last command used a temporary, non-repository Pest bootstrap to attach query observation to the existing test file. It did not add tests, change their assertions, substitute authentication services, or change the session driver. Its observation data and local inspection logs were read before removing the container's temporary bootstrap/data. Repeating the ordinary authentication tests needs no bootstrap. Existing real Redis worker tests and the catalogue benchmark also ran as part of the complete suite. The standalone set adds an explicit repeat of the seven named contention files.

## 12. Changes, remaining recommendations, and final Git state

The initial findings were reported before the fix stage. **No Critical or High defect was confirmed, so no code correction or new regression test was warranted.** No sessions schema removal, monetary rename, RBAC installation, or architectural refactor was performed. Existing tests already exercised the suspected service path; temporary inspection/query observation filled the class-wiring and session-observation evidence gaps.

Only `docs/18-structural-integrity-audit.md` was created. The existing `docs/18-final-submission-review.md` was preserved. No application-owned PHP file changed, so PHP formatting was not needed for this documentation-only result.

Remaining recommendations are limited to a focused future cleanup of the fixture autoload warning and general money-shape wording, optional compatible static analysis, and the already documented production token/provisioning decisions. None calls for changing the working assessment architecture before submission.

Final Git status:

```text
 M compose.yaml
?? docs/18-structural-integrity-audit.md
```

The Compose modification is the user's pre-existing work. Branch/HEAD and milestone commits are preserved; no changes are staged and no commit, push, or merge was made. The user may independently rerun the complete suite with `docker compose exec -T api php artisan test --compact` before final submission.
