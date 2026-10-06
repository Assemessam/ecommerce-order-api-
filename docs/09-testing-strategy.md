# 09 — Testing Strategy

## Goals

Tests demonstrate business correctness, authorization isolation, transaction atomicity, API contracts, and behavior under contention. Pest is the primary test runner.

## Test layers

### Unit tests

- Pure money and discount calculations, including rounding and caps.
- Promotion eligibility rules and validity-boundary times.
- Status-transition rules.
- DTO/enum behavior where it adds meaningful confidence.

These tests avoid the framework/database where possible.

### Feature/API tests

- Route authentication, Form Request validation, status codes, and JSON shapes.
- Catalogue search/filter/sort/pagination.
- Owner vs non-owner cart/order access.
- Cart merging, quantity updates, removals, stock feedback, and promotion attachment.
- Checkout success and every documented business failure.
- Cancellation success, invalid status, and repeated cancellation.

Use factories and `LazilyRefreshDatabase` (or `RefreshDatabase` when needed) for isolated tests. Database-sensitive feature tests run against PostgreSQL, not SQLite, because lock behavior and constraints are part of the product.

### Integration/repository tests

- Query semantics, case normalization, eager loading, and pagination.
- PostgreSQL check/unique/foreign-key constraints.
- Transaction rollback after injected failures at critical checkout steps.
- Lock acquisition and deterministic ordering.

### Concurrency tests

Run separate database connections/processes against PostgreSQL with barriers so requests genuinely overlap. Milestone 5 verifies checkout contention/idempotency; Milestone 6 verifies cancellation and cancellation versus checkout. See `11-checkout.md` and `12-order-management.md` for executed evidence:

1. Stock 5; checkout quantities 4 and 3 concurrently; assert at most one incompatible allocation succeeds and final stock is never negative.
2. Promotion global limit 1; two eligible customers check out concurrently; assert one usage/order discount succeeds.
3. Per-customer limit 1; the same customer issues overlapping eligible checkouts; assert the limit holds.
4. Two cancellation requests overlap; assert stock increases once and both responses follow the chosen idempotency contract.
5. A forced exception after stock update but before order completion rolls the entire checkout back.

These are not simulated by calling service methods sequentially in one connection.

## Foundation tests

- `GET /api/health` returns the exact stable JSON response.
- Base migrations run on PostgreSQL.
- Application boots and route registration succeeds.

## Security tests

- Missing, invalid, and revoked tokens.
- Cross-customer identifiers on cart items and orders.
- Mass-assignment attempts and unrecognized inputs.
- Sort-column injection and malformed numeric filters.
- Authentication rate limiting and password/token non-disclosure.
- Production error response does not expose exception details.

## Quality gates per milestone

```bash
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api php artisan test --compact
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
```

Database/concurrency suites run inside Compose. Coverage is useful for finding gaps, but no arbitrary percentage replaces scenario coverage. The checkout and cancellation critical paths require branch/failure-path coverage.

## Test data and time

- Factories produce valid defaults; tests override only relevant fields.
- Freeze time for promotion boundary tests.
- Each test owns its records and does not depend on execution order.
- Never use production data or secrets.

## Current verified scope

Milestone 1 adds `tests/Feature/Http/Controllers/Api/AuthControllerTest.php` and `tests/Feature/ApiErrorResponseTest.php`, alongside the unchanged health and framework examples. Authentication coverage includes registration, normalized duplicate email, field validation, configured password strength and byte boundaries, hash-shaped input, login/hash upgrade, missing/invalid/revoked tokens, public profile allow-list, session rejection, current-token logout, independent device/customer tokens, registration rollback, and throttling/reset behavior. Error-contract coverage includes JSON without Accept, safe status mappings, duplicate persistence conflicts, request IDs/log context, no-store responses, and safe 500 responses with debug disabled and enabled.

Run focused authentication tests:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/AuthControllerTest.php
```

Run authentication plus API error tests:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/AuthControllerTest.php tests/Feature/ApiErrorResponseTest.php
```

`phpunit.xml` sets both `<env>` and `<server>` database variables because PHP server variables inherited from Docker can take precedence over PHPUnit environment overrides. `DB_URL` is forced empty. `Tests\TestCase::createApplication()` validates the testing environment, pgsql connection, Compose host/port, dedicated database name, and absence of a URL override before any database refresh. One test verifies the live PostgreSQL database name. Run these tests in the app container, which provides `pdo_pgsql`; host PHP currently lacks it. The project database publishes no host port and does not use `postgres-local`.

Token lifecycle tests use actual persisted Sanctum tokens and Authorization headers. Between sequential protected requests they clear cached authentication guards to model independent HTTP requests. Rate-limit tests freeze/advance time without sleeping; test cache is isolated per application. Transactions roll back test records.

No static analysis tool is installed or configured. Product catalogue API, service, repository, integrity, and seeder tests are implemented in Milestone 2 below. Cart and actual HTTP concurrency tests are implemented in Milestone 3 below. Promotions are implemented in Milestone 4 below; checkout, orders, and cancellation remain planned.


## Milestone 1 executed verification

On October 6, 2026, using the existing Compose app container:

| Check | Result |
|---|---|
| Preflight `php artisan test --compact` | 3 passed, 4 assertions |
| Authentication and API error test files | 59 passed, 302 assertions |
| Complete `php artisan test --compact` | 62 passed, 306 assertions |
| `vendor/bin/pint --dirty --format agent` | Passed; corrected import ordering |
| `composer validate --strict` | Valid |
| `composer audit` | No security vulnerability advisories |
| API route inspection | Health plus the four required auth endpoints |

The development database remained at zero users and zero tokens. `postgres-local` remained running with the same container ID/start time and zero restarts. No Docker configuration, dependency, migration, commit, or push was introduced by Milestone 1.


## Milestone 2 catalogue coverage and executed verification

Catalogue tests use the same isolated PostgreSQL database and unchanged safety guard as authentication. Test files:

- `tests/Feature/Http/Controllers/Api/ProductControllerTest.php`: active public list/detail, zero-stock visibility, literal case-insensitive name search, wildcard/SQL-fragment handling, inclusive/combined price bounds, exact large integers, availability variants, every sort/direction and ID tie-breaker, default/max/page navigation, retained filters, empty/beyond-last pages, query-only validation, unknown-key isolation, every query validation boundary, missing/inactive/malformed/overflow IDs, configured currency, and exact public resource fields.
- `tests/Feature/Models/ProductTest.php`: enum/integer casts, SKU normalization, unique normalized SKU enforced even for raw inserts, non-negative price/stock, allowed status, nonblank SKU, NOT NULL boundaries, zero and maximum signed bigint money.
- `tests/Feature/Repositories/Eloquent/EloquentProductRepositoryTest.php`: real PostgreSQL combined filtering/pagination, interface binding, explicit service-supplied status, detail lookup, and rejecting sort/direction injection from non-HTTP callers.
- `tests/Feature/Services/Product/ProductServiceTest.php`: isolated repository mocks demonstrate query propagation, active policy, successful detail lookup, and missing/inactive/invalid-ID behavior without HTTP responses or database access.
- `tests/Feature/Database/Seeders/ProductSeederTest.php`: repeated sample seeding, mixed stock/status fixtures, existing sample price/stock preservation, and no customer/unrelated-product changes.

Run the focused catalogue suite:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/ProductControllerTest.php tests/Feature/Models/ProductTest.php tests/Feature/Repositories/Eloquent/EloquentProductRepositoryTest.php tests/Feature/Services/Product/ProductServiceTest.php tests/Feature/Database/Seeders/ProductSeederTest.php
```

On October 6, 2026, using the existing Docker Compose environment:

| Check | Executed result |
|---|---|
| Complete preflight suite | 62 passed, 306 assertions; 1.14s |
| Focused catalogue suite | 110 passed, 455 assertions; 1.57s |
| Complete PostgreSQL suite after Pint, including authentication | 172 passed, 761 assertions; 2.45s |
| `vendor/bin/pint --dirty --format agent` | Passed |
| `composer validate --strict` | `./composer.json is valid` |
| `composer audit` | No security vulnerability advisories found |
| Additive development `php artisan migrate --no-interaction` | Product migration applied successfully |
| Route inspection | Seven API routes: health, four authentication routes, two catalogue routes |
| Live HTTP checks | Products 200 with native empty pagination; oversized page size 422; missing product 404; health 200; request IDs/no-store headers present |
| Development records after verification | Zero users, zero tokens, zero products; samples exercised only in isolated tests |
| Unrelated `postgres-local` | Same container ID/start time, running, zero restarts |

The initial focused run found a test-only attribute-array ordering mismatch; comparing database-loaded before/after snapshots corrected it, and the seeder test was rerun successfully. No implementation-related failures remain.

The product migration was verified against real PostgreSQL: generated bigint identity, time-zone-aware timestamps, normalized unique SKU, four CHECK constraints, and four status-led composite indexes. Development migration was additive; destructive test refreshes used only `ecommerce_order_api_test`. No customer seeder was run in development. No dependencies, Docker configuration, commits, or pushes were introduced.

Known limits: original FR-P03 SKU/description search remains deferred by the explicit name-only milestone scope. There is no specialized substring-search index or representative-load performance benchmark. No static analyzer is installed. No cart/order/concurrency functionality is claimed by this milestone.


## Milestone 3 shopping cart coverage and concurrency mechanism

Cart API tests cover all required endpoints with missing/invalid/revoked bearer authentication, empty reads with no write, owner isolation with/without an existing caller cart, forged owner/cart/product/price keys, add/merge/update/delete semantics, missing/inactive products, changed/out-of-stock products, invalid/missing/overflow quantities and IDs, server-derived/current prices, exact large amounts, configured currency, response allow-lists, overflow recovery, request IDs/no-store headers, and three-query eager loading for twelve lines. Model tests exercise PostgreSQL unique/CHECK/NOT NULL/FK constraints, cascade/restrict behavior, and model relationships. Service tests cover dependency binding, policy protection even if a repository supplies a wrong owner, cart-before-product lock ordering, internal quantity rules, business eligibility, changed-stock revalidation, maximum bigint accumulation, safe integrity mapping, and rollback after post-insert/calculation failures.

Policy tests exercise both view/update abilities for owner/non-owner and verify 404 denials.

Focused suite:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/CartControllerTest.php tests/Feature/Models/CartTest.php tests/Feature/Policies/CartPolicyTest.php tests/Feature/Services/Cart/CartServiceTest.php tests/Feature/Services/Cart/CartConcurrencyTest.php
```

Actual concurrency tests are in `tests/Feature/Services/Cart/CartConcurrencyTest.php`; `tests/Fixtures/cart-request.php` is a test-only PHP subprocess worker. Each worker boots the application, checks the isolated test connection before any query, and handles an authenticated JSON POST through the real HTTP kernel. Symfony Process passes the token through stdin rather than command arguments and propagates explicit testing environment/database configuration. No external development HTTP server is used.

The test uses `DatabaseMigrations` rather than `LazilyRefreshDatabase`, so fixtures are committed and visible across connections. It asserts zero initial transaction nesting and Read Committed isolation. The parent holds a product lock, launches two independent processes, and polls `pg_stat_activity` through a separate observer connection until **both distinct worker backend PIDs are active and waiting on locks**. Queries must show the product `FOR UPDATE` and cart contention. Only then does it release the barrier. A five-second deadline fails the test instead of pretending sequential execution was concurrent; workers have bounded lock/process timeouts and cleanup always releases locks/stops workers. The short poll sleep synchronizes on observed database state, not a guessed overlap delay.

Verified scenarios:

| Scenario | HTTP results | Persisted result |
|---|---|---|
| No cart, stock 10, simultaneous +3/+3 | 201 + 201; responses contain quantities 3 and 6 | One cart, one line, quantity 6; stock 10 |
| Existing quantity 1, stock 10, simultaneous +3/+3 | 201 + 201; responses contain quantities 4 and 7 | One cart, one line, quantity 7; stock 10 |
| No cart, stock 5, simultaneous +3/+3 | 201 + 409 INSUFFICIENT_STOCK | One cart, one line, quantity 3; stock 5 |

This evidence concerns cart mutation/creation safety. It does not prove future checkout allocation or promotion safety. All future cart writers must honor the same cart-lock convention. Run suite invocations sequentially; concurrent `migrate:fresh` runs against this single test database can interfere. Ordinary feature tests continue to use the existing test guard and transaction isolation. The initial development of these tests encountered an accidental overlap between two suite invocations and was rerun sequentially; final results below supersede those setup failures.


## Milestone 3 executed verification — October 6, 2026

All commands below used the existing Compose environment. Final checks after policy addition and Pint:

| Check | Exact result |
|---|---|
| Complete preflight PostgreSQL suite | 172 passed, 761 assertions; 2.49s |
| Final focused cart suite (five test files) | 99 passed, 442 assertions; 2.87s |
| Standalone concurrent HTTP tests | 3 passed, 51 assertions; 1.07s; also passed in the final focused/full suites |
| Final complete PostgreSQL regression suite | 271 passed, 1203 assertions; 5.21s |
| `vendor/bin/pint --dirty --format agent` | Exit 0; corrected formatting/imports, including final service-test whitespace |
| `composer validate --strict` | Exit 0; `./composer.json is valid` |
| `composer audit` | Exit 0; no security vulnerability advisories found |
| Static analysis | Not run: none installed/configured |
| `git diff --check` | Passed |
| Additive development `php artisan migrate --no-interaction` | Both cart migrations applied successfully |
| Artisan schema inspection | Bigint identities, owner/line uniqueness, expected indexes, cascade/restrict FKs, timestamptz fields |
| API route inspection | Eleven API routes including all four cart routes |
| Live unauthenticated GET `/api/cart` | 401 UNAUTHENTICATED; matching request ID, no-store/private headers |
| Development row counts after verification | Users 0, tokens 0, products 0, carts 0, cart_items 0 |
| Unrelated `postgres-local` | Same ID/start time as preflight; running, zero restarts |

The database-sensitive suites used only `ecommerce_order_api_test`; the development database received additive schema migration only. No container restart/reset, dependency change, unrelated code modification, commit, or push was made. The baseline Git tree was clean with latest commits `f84fefb` (catalogue) and `4d10dfc` (authentication/foundation); the final changes are unstaged milestone work. Boost schema inspection returned an empty schema in its context, so actual Compose schema was inspected with Artisan. Initial validation-message/JSON float encoding test expectations and the overlapping suite setup issue were corrected; final runs above pass.

Known limits: GET is an unlocked estimate, popular product row locks may serialize cart mutations across customers, signed-bigint overflow returns a conflict instead of an arbitrary-precision amount, and no load benchmark is claimed. Concurrency workers exercise actual authenticated HTTP kernel requests on separate processes/connections, not network HTTP transport. Checkout stock allocation, promotions, and orders remain outside this milestone. The recommended next milestone is **Milestone 4: Promotions** after cart review and confirmation of the promotion proposals.

### Files created

- `app/Contracts/Repositories/CartRepositoryInterface.php`
- `app/DTOs/Cart/CartLine.php`
- `app/DTOs/Cart/CartView.php`
- `app/Exceptions/Domain/CartConflictException.php`
- `app/Exceptions/Domain/CartTotalTooLargeException.php`
- `app/Exceptions/Domain/InactiveProductException.php`
- `app/Exceptions/Domain/InsufficientStockException.php`
- `app/Http/Controllers/Api/CartController.php`
- `app/Http/Requests/Cart/AddCartItemRequest.php`
- `app/Http/Requests/Cart/UpdateCartItemRequest.php`
- `app/Http/Resources/CartItemResource.php`
- `app/Http/Resources/CartResource.php`
- `app/Models/Cart.php`
- `app/Models/CartItem.php`
- `app/Policies/CartPolicy.php`
- `app/Repositories/Eloquent/EloquentCartRepository.php`
- `app/Services/Cart/CartService.php`
- `database/factories/CartFactory.php`
- `database/factories/CartItemFactory.php`
- `database/migrations/2026_10_06_135748_create_carts_table.php`
- `database/migrations/2026_10_06_135749_create_cart_items_table.php`
- `tests/Feature/Http/Controllers/Api/CartControllerTest.php`
- `tests/Feature/Models/CartTest.php`
- `tests/Feature/Policies/CartPolicyTest.php`
- `tests/Feature/Services/Cart/CartConcurrencyTest.php`
- `tests/Feature/Services/Cart/CartServiceTest.php`
- `tests/Fixtures/cart-request.php`

### Existing files modified

- `README.md`
- `app/Contracts/Repositories/ProductRepositoryInterface.php`
- `app/Models/Product.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Repositories/Eloquent/EloquentProductRepository.php`
- `bootstrap/app.php`
- `docs/01-business-discovery.md`
- `docs/02-requirements.md`
- `docs/03-business-processes.md`
- `docs/04-mvp-scope.md`
- `docs/05-architecture.md`
- `docs/06-database-design.md`
- `docs/07-api-contracts.md`
- `docs/08-business-rules.md`
- `docs/09-testing-strategy.md`
- `docs/10-implementation-roadmap.md`
- `routes/api.php`

## Milestone 4 executed verification — October 6, 2026

Preflight used the existing Compose API/PostgreSQL services. PHP is 8.5.11, Laravel 13.34.0, Sanctum 4.3.3, Pest 4.7.8, PHPUnit 12.5.33, and Pint 1.32.1, confirmed from the installed runtime/dependencies. The last commits were `f84fefb` (catalogue) and `4d10dfc` (authentication). Existing uncommitted cart code and documentation were preserved; there is no `.ai/rules` directory. A pre-edit snapshot outside the repository was used to distinguish this milestone's edits from earlier work.

All database tests ran inside Docker against guarded `ecommerce_order_api_test` at Compose host `postgres`. No suite invocations overlapped during final verification; `postgres-local` was never modified.

| Check | Exact result |
|---|---|
| Complete PostgreSQL baseline | 271 passed; 1203 assertions; 6.08 seconds |
| Combined focused promotion tests, excluding contention | 133 passed; 557 assertions; 3.07 seconds |
| Complete PostgreSQL regression, including contention | 407 passed; 1819 assertions; 9.58 seconds |
| Standalone existing cart + promotion concurrency files | 6 passed; 110 assertions; 2.32 seconds |
| Promotion contention file by itself | 3 passed; 59 assertions; 1.24 seconds |
| `vendor/bin/pint --dirty --format agent` | Passed; initial run corrected imports/spacing/PHPDoc; final run reported passed |
| `composer validate --strict` | Valid; exit 0 |
| `composer audit` | No security vulnerability advisories; exit 0 |
| `git diff --check` | Passed |
| Development additive migrations | All three new migrations applied, batch 4; no existing migrations reset |
| Development schema inspection | Promotions and ledger column types, microsecond validity timestamps, unique indexes and restricted FKs verified with Artisan |
| Route inspection | 13 API routes; authenticated promotion POST/DELETE present; no checkout/order/redemption route |

Early focused tests exposed loss of timezone offsets and fractional seconds with Eloquent's default date serialization. Models now preserve offsets/microseconds, and eligibility tests reload records before checking equivalent-zone starts and the instant before expiry. An input test was corrected to use an embedded null byte because Laravel's existing TrimStrings middleware removes edge control characters. All final runs above pass; no failing check remains. No static analyzer is installed/configured, and no dependency was added.

### Coverage and boundaries

- Pure calculator tests cover percentage/fixed discounts, caps for both types, subtotal/final bounds, zero subtotal, fractional half-up boundaries, invalid inputs, exact integers beyond IEEE-754 precision, and PHP_INT_MAX without multiplication overflow.
- PostgreSQL model tests cover canonical uniqueness/import rejection, allowed types, percentage bounds, fixed positivity, monetary bounds, positive/null limits, active/minimum defaults, date ordering, selected promotion FK, deletion behavior, ledger FKs, unique redemption keys, non-negative snapshots, and relationships.
- Eligibility tests cover active/date windows (inclusive start/exclusive expiry), UTC offsets/microseconds, exact minimum boundaries, empty/unpurchasable carts, normalization, unknown codes, global/customer counts, other-customer isolation, and unlimited use.
- API/service/repository tests cover Sanctum failures, input validation, forged ownership/money, fixed/percentage responses, replacement/repeated application/removal, rollback after persistence, safe conflicts, current multi-line totals, quantity/price/stock/status/date/usage changes, selected-code retention, zero invalid discounts, no stock or redemption side effects, exact large totals, overflow recovery by removal, eager loading, and one-query usage aggregation.
- PromotionSeeder inserts seven missing examples repeatably and preserves existing promotions/products/customers; it creates no redemption records.
- Three new contention tests use separate PHP processes running authenticated HTTP kernel requests and independent PostgreSQL connections. Committed fixtures and a held cart row form a barrier; both workers must appear as active PostgreSQL lock waiters before release. Cases are competing replacements, apply versus remove, and simultaneous removals. Each asserts response/persistence consistency and unchanged inventory/ledger. These tests exercise kernel requests, not network transport.
- All old auth/catalogue/cart tests remain; only cart response expectations and constructor wiring were extended for the intentional new contract. No tests were deleted.

### Implemented versus future checkout

Implemented: constrained promotion/ledger schemas, selection APIs, shared integer calculator, preliminary eligibility against committed history, safe cart serialization and rollback, stale-discount suppression, and realistic factories/seeder.

Not implemented/verified: checkout, orders/items, inventory allocation/restoration, successful redemption writes, real order linkage, checkout replay/idempotency enforcement, usage-limit consumption under contention, or cancellation policy. The future workflow must add a real unique order FK, persist/reuse a stable redemption key, and lock Cart → Products ascending → Promotion → Customer ledger before checking counts and inserting redemption in the same successful order/inventory/cart transaction. The promotion lock also serializes first-use customers when no usage row exists. UUID uniqueness alone is not a claim of exactly-once checkout behavior.

Known trade-offs: unlocked Read Committed estimates can change immediately; selecting a promotion never reserves its remaining uses. All lines must be purchasable for any discount. Positive caps apply to fixed and percentage discounts; NULL limits/bounds are unlimited/unbounded. Invalid selections are retained until removed or eligible again. Raw imports must normalize codes. Indexed historical counts have no measured performance guarantee. Ledger history restricts customer/promotion deletion pending retention/privacy decisions. Seeded future/expired dates are not refreshed on reruns.

### Files changed by this milestone

Comparison against the preflight snapshot identifies **32 created files and 23 modified files**. This list excludes unchanged pre-existing cart/catalogue work still shown by Git.

Created:


- `app/Contracts/Repositories/PromotionRepositoryInterface.php`
- `app/DTOs/Promotion/DiscountCalculationResult.php`
- `app/DTOs/Promotion/PromotionEligibilityResult.php`
- `app/Enums/PromotionIneligibilityReason.php`
- `app/Enums/PromotionType.php`
- `app/Exceptions/Domain/PromotionNotEligibleException.php`
- `app/Http/Controllers/Api/CartPromotionController.php`
- `app/Http/Requests/Cart/ApplyPromotionRequest.php`
- `app/Http/Resources/PromotionResource.php`
- `app/Models/Promotion.php`
- `app/Models/PromotionRedemption.php`
- `app/Repositories/Eloquent/EloquentPromotionRepository.php`
- `app/Services/Cart/CartPricingService.php`
- `app/Services/Cart/CartPromotionService.php`
- `app/Services/Promotion/PromotionCalculator.php`
- `app/Services/Promotion/PromotionService.php`
- `database/factories/PromotionFactory.php`
- `database/factories/PromotionRedemptionFactory.php`
- `database/migrations/2026_10_06_142158_create_promotions_table.php`
- `database/migrations/2026_10_06_142159_create_promotion_redemptions_table.php`
- `database/migrations/2026_10_06_142200_add_promotion_id_to_carts_table.php`
- `database/seeders/PromotionSeeder.php`
- `tests/Feature/Database/Seeders/PromotionSeederTest.php`
- `tests/Feature/Http/Controllers/Api/CartPromotionControllerTest.php`
- `tests/Feature/Models/PromotionRedemptionTest.php`
- `tests/Feature/Models/PromotionTest.php`
- `tests/Feature/Repositories/Eloquent/EloquentPromotionRepositoryTest.php`
- `tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php`
- `tests/Feature/Services/Cart/CartPromotionServiceTest.php`
- `tests/Feature/Services/Promotion/PromotionServiceTest.php`
- `tests/Fixtures/cart-promotion-request.php`
- `tests/Unit/Services/Promotion/PromotionCalculatorTest.php`

Modified:

- `app/Contracts/Repositories/CartRepositoryInterface.php`
- `app/DTOs/Cart/CartView.php`
- `app/Http/Resources/CartResource.php`
- `app/Models/Cart.php`
- `app/Repositories/Eloquent/EloquentCartRepository.php`
- `app/Services/Cart/CartService.php`
- `tests/Feature/Http/Controllers/Api/CartControllerTest.php`
- `tests/Feature/Services/Cart/CartServiceTest.php`
- `README.md`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `docs/01-business-discovery.md`
- `docs/02-requirements.md`
- `docs/03-business-processes.md`
- `docs/04-mvp-scope.md`
- `docs/05-architecture.md`
- `docs/06-database-design.md`
- `docs/07-api-contracts.md`
- `docs/08-business-rules.md`
- `docs/09-testing-strategy.md`
- `docs/10-implementation-roadmap.md`
- `routes/api.php`

### Final Git and environment state

Git remains uncommitted: 18 tracked modified files and 59 untracked files (expanded paths), including the original Milestone 3 work. No files were staged, committed, or pushed. Existing product-model/repository changes, cart migrations/factories/policy/worker, authentication code, dependencies, environment configuration, and unrelated files were preserved. Three additive development migrations were applied to `ecommerce_order_api` on the project Compose database only. No sample seeder was run against development data; the explicit optional command is in README. This milestone stops at promotions/cart integration.

## Milestone 5 executed verification

The checkout controller, service, order-integrity, and independent-process checkout suites cover atomic purchase creation, shared integer pricing, snapshots, promotion consumption, owner-scoped replay, PostgreSQL constraints, five rollback boundaries, ordered locks, four observed-barrier contention scenarios, and bounded retries/exhaustion after injected PostgreSQL errors. No existing tests were deleted; one legacy-ledger assertion now checks nullable real-order compatibility. Full execution results, backend PIDs/outcomes, limitations, and file inventory are in [`11-checkout.md`](11-checkout.md). Earlier milestone reports above remain historical.


## Milestone 6 executed verification

OrderControllerTest covers token protection, ownership, pagination/validation, deterministic newest-first summaries, snapshot details, no private/live relations, idempotent cancellation, inactive restoration, preserved promotion ledger, and old-key checkout replay. OrderServiceTest injects failures during a later restore, before status saving, after status/markers, and a real unexpected database error; it checks no surviving partial inventory or markers. It also covers overflow, conditional-update refusal, defensive inconsistent/missing states, policy enforcement, safe integrity conflict, transaction levels, ordered locks, and bounded query counts. OrderCancellationTest checks PostgreSQL state constraints, exact signed-bigint boundaries, upgrade history preservation, and honest downgrade refusal. OrderPolicyTest exercises the complete owner/foreign-owner ability matrix.

OrderConcurrencyTest reuses the existing guarded Symfony PHP worker and independent PostgreSQL connections with committed fixtures. The observer requires two distinct active lock waiters before releasing an order/product barrier. Same-owner cancellation proves one status update and one stock restoration, with identical 200 responses. Cancellation/checkout overlap and reverse-insertion multiple products verify exact final inventory and ascending product locks. Two injected PostgreSQL deadlock cases exercise whole-transaction retries/exhaustion; these injections are distinct from real contention tests. Worker telemetry now includes successful order UPDATE and inventory increment counts without binding values.

The downgrade guard intentionally refuses retained cancelled orders. Concurrency teardown truncates only order fixtures and their dependent test history in the guarded test database before DatabaseMigrations rollback. Run all suites sequentially, never concurrently on the shared test database. Exact final results and backend PID evidence are in `12-order-management.md`. Earlier milestone reports remain historical.

## Bonus Milestone 7B — Real Redis catalogue coverage

`tests/Feature/Services/Product/ProductCatalogueCacheTest.php` uses DatabaseMigrations, guarded PostgreSQL, and real project Redis. Its 50 cases cover initial product queries versus zero product queries on warm hits, every filter/page/sort key, normalized/default/boolean query reuse, validation with a warm cache, inactive isolation, pagination and host-specific links, uncached details/admin reads, and current resource currency. Scalar snapshot reconstruction is checked through identical complete JSON responses.

Mutation coverage includes creation; name/SKU/description/null-description/price/status/stock edits; changed search/range/availability membership and totals; sample seeding; checkout deduction; cancellation restoration; unchanged/empty/replayed operations. Explicit outer transactions exercise nested creation/update/checkout/cancellation rollback. Transactional listing reads cannot publish uncommitted values. A deterministic old-reader interleaving reads the old PostgreSQL page, commits an admin edit, then attempts to publish the old page: the next request must read the database and then cache the new result. Marker eviction must also choose a new generation.

Failure tests use an actual refused TCP connection, missing Redis connection/store configuration, failure during population, database/application exception propagation, and a callback that makes Redis unavailable after the purchase's database commit but before catalogue invalidation. They verify purchase response/state, permanent key replay and safe cancellation. Stale Redis values cannot override authoritative checkout price/stock/status. Warning assertions cover safe diagnostic context and filesystem throttling. Real Redis expiry uses a bounded 2.1-second wait because PHP fake time cannot advance Redis's clock.

PHPUnit disables caching for existing tests, forces Redis catalogue DB 3 and a testing namespace; these new tests explicitly enable caching and add a unique UUID per case. Teardown enumerates/deletes only that case's keys, asserts removal, and never flushes a Redis database. A sentinel test proves invalidation does not remove unrelated keys. Committed order fixtures are truncated only in the guarded test database before the existing placed-only downgrade, matching the concurrency-suite convention. Database suites must still run sequentially.

The `catalogue-benchmark` group creates 200 isolated active products and measures default, filtered and paginated HTTP-kernel requests, with one cold request and 25 disabled-cache/25 warm-cache samples each. Query assertions check two product SELECTs per cold page and none on warm pages. It reports actual elapsed milliseconds without requiring a timing threshold, then removes all fixtures. Repeat with:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Services/Product/ProductCatalogueCacheTest.php
docker compose exec -T api php artisan test --compact --group=catalogue-benchmark
docker compose exec -T api php artisan test --compact
```

The complete suite requires this project's Redis service and PhpRedis extension. Existing independent-process PostgreSQL contention suites are also executed with caching enabled and an isolated Redis namespace. Exact final counts, benchmark values and quality results are recorded in [15-redis-caching.md](15-redis-caching.md); earlier milestone evidence above is historical.
