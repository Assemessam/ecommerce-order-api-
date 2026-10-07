# Milestone 7E — Final audit and submission preparation

Historical pre-refactor review: the findings, class names, authorization model, Git state, and executed counts below record the October 6 delivery. The subsequent approved ProductService/Spatie RBAC change is documented and verified in [19-product-service-rbac-refactor.md](19-product-service-rbac-refactor.md). This record is retained rather than repurposed as evidence for the later implementation.

Review date: **October 6, 2026** (Africa/Cairo). Employer deadline: **October 13, 2026**.

## 1. Executive summary

**The implementation passes the mandatory requirements and all seven bonus categories in the supplied assessment summary.** This conclusion comes from current source review, actual PostgreSQL constraints, newly executed regression/concurrency suites, and a fresh-clone HTTP rehearsal. Earlier milestone counts were treated as historical evidence.

No confirmed Critical or High defect was found. No application code, dependencies, migrations, or tests needed correction. Final changes are this report, README corrections, and the milestone roadmap update. The review is an assessment readiness check, not a production deployment approval.

Bonus 7D was independently verified and committed on `feature/api-rate-limiting-7d` as **`a21aab30064583e820c59dbb9c4bf9d5cca3fbfd`**, message `feat: add Redis-backed API rate limiting`. Its parent is `49732ad851d8163e47b91be2979a0ffda8cc974d`. The working tree was clean immediately afterward. `release/ecommerce-assessment-final` was created directly at the completed 7D commit; all earlier commits remain ancestors. No merge, push, deployment, or submission occurred. Final review changes remain uncommitted.

The exact original **Senior Laravel Developer — E-Commerce Technical Assessment** file was not found. The authoritative baseline available for this review is the employer summary supplied with Milestone 7E, supported by [recorded requirements](02-requirements.md). Compliance with any omitted wording in the unavailable original remains **unverified**.

## 2. Employer requirements compliance matrix

Paths in this matrix are relative to the repository root. Test paths use these abbreviations: **HTTP** = `tests/Feature/Http/Controllers/Api/`; **Services** = `tests/Feature/Services/`; **Models** = `tests/Feature/Models/`. Every named test file was executed in both complete suites. “Verified” means implemented, independently reviewed, and covered by executed tests; it does not assert exhaustive formal verification.

| Requirement | Source files | Tests | Verification | Status |
| --- | --- | --- | --- | --- |
| Product ID, name, SKU, description, price, stock, status, timestamps | `app/Models/Product.php`; `database/migrations/2026_10_06_132432_create_products_table.php`; `app/Http/Resources/ProductResource.php` | Models `ProductTest.php`; HTTP `ProductControllerTest.php` | Migration, bigint/nonnegative/status/SKU constraints; explicit resource fields; HTTP create/detail rehearsal | **PASS — verified** |
| GET product list and detail | `routes/api.php`; `app/Http/Controllers/Api/ProductController.php`; `app/Services/Product/ProductService.php` | HTTP `ProductControllerTest.php`; Services `Product/ProductServiceTest.php` | Active-only list/detail, missing/inactive lookup; TCP requests | **PASS — verified** |
| Name search, min/max price, availability, sorting, pagination | `app/Http/Requests/Product/ProductQueryRequest.php`; `app/DTOs/Product/ProductQuery.php`; `app/Repositories/Eloquent/EloquentProductRepository.php` | HTTP `ProductControllerTest.php`; `tests/Feature/Repositories/Eloquent/EloquentProductRepositoryTest.php` | Literal escaped search; inclusive minor-unit filters; sort allow-list/ID tie-breaker; bounded pages; combined TCP query | **PASS — verified** |
| Authenticated customers | `app/Services/Auth/AuthService.php`; `app/Http/Requests/Auth/`; `config/auth.php`; `config/sanctum.php`; protected routes | HTTP `AuthControllerTest.php`, `AdminAuthorizationTest.php` | Registration/login/token authentication/revocation; smoke verifies 401 after logout | **PASS — verified** |
| POST/PATCH/DELETE cart items and GET cart | `app/Http/Controllers/Api/CartController.php`; `app/Services/Cart/CartService.php`; `app/Repositories/Eloquent/EloquentCartRepository.php` | HTTP `CartControllerTest.php`; Services `Cart/CartServiceTest.php`, `Cart/CartConcurrencyTest.php` | Owner-scoped access, absent cart estimate, merge/update/delete; all four TCP endpoints | **PASS — verified** |
| Customer quantity cannot exceed available stock at mutation | `app/Services/Cart/CartService.php`; product/cart repository locks | HTTP `CartControllerTest.php`; Services `Cart/CartConcurrencyTest.php` | Locked current stock, overflow-safe accumulated quantity; smoke quantity 11 against stock 10 returns 409 | **PASS — verified** |
| Percentage/fixed promotion, code/value, minimum, cap | `app/Models/Promotion.php`; promotion migration; `app/Services/Promotion/PromotionCalculator.php` | Models `PromotionTest.php`; `tests/Unit/Services/Promotion/PromotionCalculatorTest.php`; Services `Promotion/PromotionServiceTest.php` | Integer basis points/minor units; exact rounding, cap, subtotal clamp; TCP cap example | **PASS — verified** |
| Promotion dates, activity, customer eligibility | `app/Services/Promotion/PromotionService.php`; `app/Services/Cart/CartPromotionService.php` | Services `Promotion/PromotionServiceTest.php`, `Cart/CartPromotionServiceTest.php`; HTTP `CartPromotionControllerTest.php`, `CheckoutControllerTest.php` | UTC start inclusive/end exclusive; inactive/unknown/expired/minimum/invalid-cart outcomes | **PASS — verified** |
| Global and per-customer usage limits | `app/Services/Checkout/CheckoutService.php`; `app/Repositories/Eloquent/EloquentPromotionRepository.php`; redemption migration | HTTP `CheckoutControllerTest.php`; Services `Checkout/CheckoutConcurrencyTest.php`, `Admin/AdminConcurrencyTest.php` | Promotion lock covers counts and insert; global last-use contention; customer exhaustion including retained cancellation usage | **PASS — verified functional behavior and lock protocol** |
| Checkout validates cart, inventory, server prices, coupon | `app/Services/Checkout/CheckoutService.php`; `app/Services/Cart/CartPricingService.php`; promotion service | HTTP `CheckoutControllerTest.php`; Services `Checkout/CheckoutServiceTest.php` | Revalidation under locks; empty/unavailable/invalid promotion failure; TCP price changed before purchase | **PASS — verified** |
| Exact subtotal, discount, final total | Pricing service; promotion calculator; `app/Repositories/Eloquent/EloquentOrderRepository.php` | Calculator unit file; HTTP `CheckoutControllerTest.php`; Models `OrderTest.php` | Integer boundaries, half-up/capped/clamped calculations, overflow rejection and database arithmetic checks | **PASS — verified** |
| One transaction deducts stock, creates order/items, records use, clears cart | Checkout service; cart/product/order/promotion/outbox repositories | Services `Checkout/CheckoutServiceTest.php`, `Checkout/CheckoutConcurrencyTest.php` | Fault injection at critical writes; conditional deduction failure; rollback; smoke one purchase/replay | **PASS — verified** |
| Order and item tables; historical name/SKU/price snapshots | Order/item migrations; `app/Models/Order.php`, `OrderItem.php`; order repository/resources | Models `OrderTest.php`; HTTP `CheckoutControllerTest.php`, `OrderControllerTest.php` | Stored purchase values survive product/promotion/currency changes; smoke preserves 1501 unit price after admin edit | **PASS — verified through supported writers** |
| Customer order history/details and access isolation | `app/Services/Order/OrderService.php`; order repository; `app/Policies/OrderPolicy.php` | HTTP `OrderControllerTest.php`; `tests/Feature/Policies/OrderPolicyTest.php` | Customer-scoped pagination/details; foreign/missing indistinguishable 404; TCP foreign-order check | **PASS — verified** |
| Cancellation eligibility, status change, stock restoration once | Order service; product/order repositories; cancellation metadata migration | HTTP `OrderControllerTest.php`; Services `Order/OrderServiceTest.php`, `Order/OrderConcurrencyTest.php`; Models `OrderCancellationTest.php` | Locked placed→cancelled transition, equal markers, conditional increments, overflow rollback; repeated TCP cancellation | **PASS — verified** |
| Stock 5, competing purchases 4 and 3 never oversell | Checkout service; sorted product locks; guarded decrement; product stock CHECK | Services `Checkout/CheckoutConcurrencyTest.php` | Two observed PostgreSQL lock waiters; final 201/409, one order, stock 2; same case rerun with Redis caching enabled | **PASS — verified** |
| Missing products, insufficient stock, invalid/expired/exhausted coupons | Domain exceptions; `bootstrap/app.php`; cart/checkout/promotion services | HTTP product/cart/promotion/checkout files; `tests/Feature/ApiErrorResponseTest.php` | Executed 404/409/422 envelopes, retained cart and no partial order | **PASS — verified** |
| Unauthorized orders, invalid states, empty carts | Order policy/service; checkout service; centralized exception handling | HTTP `OrderControllerTest.php`, `CheckoutControllerTest.php`; Services order/checkout files | Guest 401, foreign 404, invalid-state/empty-cart 409, sanitized failure responses | **PASS — verified** |
| Git repository, migrations, seeders/factories | Git history; `database/migrations/`, `database/factories/`, `database/seeders/` | Models files; `tests/Feature/Database/Seeders/` | Fresh local clone; 17 migrations; six sample products/seven promotions; clean dependency install | **PASS — verified** |
| README setup/configuration/architecture/concurrency/trade-offs | `README.md`; `docs/05-architecture.md`, `06-database-design.md`, `11-checkout.md`, `12-order-management.md` | Setup rehearsal and full fresh-clone suite | Commands executed with separate Compose infrastructure; final links/config descriptions corrected | **PASS — verified local reproducibility** |
| Postman collection or API documentation | `postman/Ecommerce_Order_Promotion_API.postman_collection.json`; `docs/07-api-contracts.md` | Structural/JavaScript checks; route reconciliation; TCP smoke | 27 requests cover all 25 API entries; payloads and 28 scripts parse/compile; Newman not run | **PASS — deliverable verified; runner execution partial** |

The suite does not isolate a simultaneous **per-customer-only** promotion limit race as a separate scenario. Functional exhaustion is tested; checkout's shared cart and promotion serialization is reviewed and exercised by other contention cases. That specific independent-process scenario remains **unverified**, and is not represented as an executed test.

## 3. Bonus feature compliance matrix

| Bonus | Implementation evidence | Executed verification | Status |
| --- | --- | --- | --- |
| Redis product listing caching | `app/Services/Product/ProductCatalogueCache.php`; `config/catalogue.php`, `cache.php`, `database.php` | 50 cache cases; normalized keys, TTL, invalidation, rollback, stale readers, outage fallback; 34 contention cases with caching enabled | **PASS — local integration verified** |
| Queue/event order processing | `app/Services/Order/OrderOutboxService.php`, `OrderEventProcessingService.php`; outbox repository; `app/Jobs/ProcessOrderEvent.php`; events/listener | 28 job/outbox cases; worker crash/retry/lease recovery; TCP purchase/cancellation produced two processed events/two effects | **PASS — verified** |
| Checkout idempotency | Checkout request/service; customer/key unique index | Focused checkout 53 cases; competing-key processes 201/200; smoke same-key replay without repeated effects | **PASS — verified** |
| API rate limiting | `app/Http/RateLimiting/ApiRateLimiters.php`; `app/Http/Middleware/ThrottleApiRequests.php`; named route policies | 103 focused cases; 4 standalone atomic/isolation cases; TCP default 429 and actual outage 503 | **PASS — verified** |
| Docker/Sail | `Dockerfile`; `compose.yaml`; PostgreSQL initialization SQL | Fresh Docker build/install/start/migrate/seed; worker/scheduler profiles; independent PostgreSQL/Redis; full fresh suite | **PASS — Docker implementation verified** |
| Indexes and explanation | Product/order/cart/ledger/outbox migrations; `docs/06-database-design.md`; README | Actual `pg_indexes` inspection: 54 public indexes, including normalized SKU, customer/key, product sort and partial outbox due indexes | **PASS — existence and rationale verified** |
| Admin product/promotion APIs | Admin controllers/Form Requests; Product/Promotion policies; administration services; `users.is_admin` migration | Authorization/validation/mass assignment tests; contention suite; all eight admin API entries exercised over TCP | **PASS — verified** |

Index effectiveness under production-sized data, external event delivery, production Redis durability, and production throughput remain **unverified**. These local results are not capacity claims.

## 4. Architecture overview

The approved flow is preserved: **Controller → Service → Repository Interface → Eloquent Repository → PostgreSQL**. Controllers coordinate validated input, injected services, resources and HTTP status; they do not contain inventory, discount, or persistence workflows. Form Requests enforce boundary rules. `AppServiceProvider` binds all six repository contracts. Services own transactions and domain decisions; repository implementations encapsulate queries and row locks. DTOs/enums carry the established contracts, and API Resources explicitly select fields.

Shared cart pricing/promotion eligibility/calculation serves both estimates and checkout. Customer-scoped repositories and policies enforce ownership; admin policies consult the current stored flag. Exception normalization/request correlation stays in `bootstrap/app.php` and `ApiRequestContext`. Existing Sanctum token creation/revocation in AuthService is the documented framework persistence exception. No new architecture, duplicate pricing logic, or speculative abstraction was introduced.

## 5. Database and concurrency findings

Actual freshly migrated PostgreSQL **17.11** was inspected read-only: **25 CHECK constraints, 14 foreign keys, 54 indexes**, and **Read Committed** isolation. Boost's host-side schema tool returned no tables, so the actual disposable Compose PostgreSQL instance was inspected with read-only `psql`; an empty host schema was not treated as application evidence.

Checkout locks **Cart → Products ascending ID → Promotion**, then counts usage and persists one outcome before commit. Product deductions additionally require enough current stock. Nonnegative stock, positive quantities, money arithmetic, enum states, normalized codes/SKUs and real references are enforced in PostgreSQL. Customer/key, cart/product, order/product, order/redemption, order/event and event/effect uniqueness protect the corresponding identities.

Cancellation locks **owned Order → Products ascending ID**, restores historical quantities once and commits equal cancellation/restoration timestamps. It acquires neither a cart nor a promotion afterward. Promotion usage remains counted. Admin stock changes use the same product lock and signed adjustments; promotion edits use the checkout promotion lock and reject limits below consumed usage. Bounded deadlock retries rerun whole transactions; exhaustion returns safe conflicts. Injected deadlock cases verify retry behavior, not a witnessed PostgreSQL deadlock cycle.

The cache rotates its generation only after commit. Old readers write only under their captured generation and check it before population; checkout never trusts cached price/stock. Failed invalidation can retain an old estimate until TTL, an explicit bounded freshness trade-off.

The outbox is inserted in the purchase/cancellation transaction. Claims use bounded `FOR UPDATE SKIP LOCKED` batches, owner tokens and expiring leases. Workers lock/validate ownership and commit their unique local notification with processed state. The 30-second timeout is below the 90-second queue retry window and the default 300-second outbox lease. Stale jobs cannot overwrite current ownership. Delivery is at least once, with effectively-once transactional internal effects; external exactly-once delivery and event ordering are not promised.

Final observed evidence includes stock-race PostgreSQL PIDs **66342/66343** (201/409, stock 2), identical-key PIDs **66359/66360** (201/200, one order/redemption), disjoint outbox claim PIDs **66670/66671**, and duplicate processor waiters **66677/66676** leaving one effect. Tests use independent processes/connections and observed barriers, not timing-only sleeps.

## 6. Security findings

| Severity | Finding | Resolution / scope |
| --- | --- | --- |
| Critical | No confirmed finding | Reviewed authorization, financial writes, rollback, stock/usage races and secrets; executed current security tests |
| High | No confirmed finding | No bypass or duplicate purchase/restoration defect identified |
| Medium | Local Redis shares memory/eviction/outages across logical stores and has persistence disabled | Confirmed in Compose; accepted development setup. Eviction/restart can reset limiter allowances or lose queue jobs. PostgreSQL outbox retains recoverable work. Strict production enforcement/durable queues need separately operated Redis settings |
| Medium | Customer bearer tokens have no expiry by default | Confirmed Sanctum configuration; documented assessment policy. Current-token revocation is tested; production token lifecycle is outside this change |
| Low | README cache description and roadmap release state were stale | Corrected here; historical milestone reports remain labeled historical |
| Low | Native limiter adapter depends on framework middleware behavior | Reviewed against locked Laravel 13.34.0 and exercised with forced contention. Recheck after framework updates; only pre-execution policies are supported |

Passwords are hashed, token values are hashed at rest, credential collection defaults are empty, registration cannot assign `is_admin`, and admin access uses current database privilege. Identity/price fields supplied by clients cannot select a cart owner or purchase amounts. Sort identifiers are allow-listed and search values bound/escaped. API failures are sanitized even in debug mode and include generated request IDs.

Every one of the **25 defined API entries** resolves to its intended named limiter. Authentication precedes user throttling; repeated authenticated forbidden attempts may reach 429 before policy authorization. Public identity uses validated canonical IP bytes and only configured trusted peers. Proxy trust defaults to an explicit empty array, preventing provider-shaped Host inference. Account login identity is normalized and HMAC-hashed; authenticated budgets share the stored user's identity across tokens/IPs.

The adapter uses Laravel's atomic `DurationLimiter::acquire()` result once; inherited policy resolution, rejection and header construction remain native. Its no-op `hit()` prevents a second reservation. PhpRedis failures produce generic 503 before business work. Current policies contain no response-based `after()` accounting. Redis DB 6/test 7 and prefixes isolate limiter keys from catalogue DB 2/test 3 and queue DB 4/test 5; logical isolation does not isolate server availability. Background commands/jobs do not execute HTTP middleware.

A bounded credential-pattern/path scan covered **386 tracked/new paths at 7D preflight, 387 at final review, and all nine reachable commit diffs**. It found no private-key, common provider token, populated APP_KEY, or bearer-token candidates. No populated environment file, auth.json, vendor/node_modules, generated cache/log, or temporary fixture was staged. The historically tracked `storage/logs/.gitignore` is intentional. Local Compose/example passwords and test passwords are development fixtures. This is a documented scan, not a guarantee that every possible secret format was detected.

## 7. Financial correctness review

Amounts and stock use signed bigint/64-bit integers; percentages use integer basis points. The calculator decomposes quotient/remainder arithmetic to avoid multiplying a maximum subtotal by basis points, rounds half-up deterministically, then applies the discount cap and subtotal clamp. Line multiplication and subtotal accumulation reject overflow before PHP can convert arithmetic to float. Cancellation/admin stock additions are also bounded before arithmetic. Database checks protect individual order/item arithmetic; service logic constructs aggregate totals from the same snapshot lines.

The HTTP rehearsal independently checked **3702 subtotal / 300 capped discount / 3402 estimate**, then changed the server price from 1234 to 1501 before checkout. The purchase correctly became **4503 / 300 / 4203**, quantity 3. Replay matched the original response. Later admin name/price edits left historical name and 1501 unit price unchanged. Calculator/checkout/constraint tests also cover percentages, fixed discounts, zero amounts, exact boundaries, caps, subtotal clamps and maximum integers. Clients must handle large JSON integers losslessly above 2^53−1.

Snapshots are immutable through supported services/endpoints; privileged SQL or a future unsupported writer can bypass that contract. No payment, tax, shipping, conversion, or refund behavior is implied.

## 8. Complete verification results

All commands below completed with **exit 0**. Repeated suites overlap; their counts must not be added together as distinct coverage. No skipped/warning/failing tests were reported.

### Before the 7D commit

| Check | Actual result | Duration |
| --- | --- | --- |
| `docker compose exec -T api php artisan test --compact` | **874 passed, 5,810 assertions** | 48.57 s |
| Limiter/middleware/concurrency + authentication | **103 passed, 1,125 assertions** | 3.93 s |
| Five PostgreSQL contention files | **34 passed, 631 assertions** | 12.83 s |
| Jobs/outbox service/model/concurrency | **28 passed, 203 assertions** | 5.74 s |
| Catalogue caching | **50 passed, 588 assertions** | 12.54 s |
| Standalone Redis admission/isolation/checkout/cleanup | **4 passed, 148 assertions** | 1.78 s |
| Pint `--dirty --format agent`; Composer `validate --strict`/`audit`; Compose `config --quiet`; Git whitespace | Passed; Composer valid, no advisories | — |

### Final release quality gate

| Check | Actual result | Duration |
| --- | --- | --- |
| Required full command: `docker compose exec -T api php artisan test --compact` | **874 passed, 5,810 assertions** | 51.44 s |
| Full suite in separately cloned Compose project | **874 passed, 5,810 assertions** | 51.18 s |
| `CheckoutServiceTest.php` + `CheckoutControllerTest.php` | **53 passed, 520 assertions** | 1.94 s |
| Five standalone PostgreSQL contention suites with catalogue cache and HTTP throttle enabled | **34 passed, 631 assertions** | 12.71 s |
| `ProductCatalogueCacheTest.php` | **50 passed, 588 assertions** | 12.75 s |
| `ProcessOrderEventTest.php`, `OrderOutboxConcurrencyTest.php`, `OrderOutboxServiceTest.php`, `OrderEventPersistenceTest.php` | **28 passed, 203 assertions** | 5.82 s |
| `ThrottleApiRequestsTest.php`, `RateLimitConcurrencyTest.php`, `AuthControllerTest.php` | **103 passed, 1,125 assertions** | 4.02 s |
| Standalone `RateLimitConcurrencyTest.php` | **4 passed, 148 assertions** | 1.87 s |
| Pint `--dirty --format agent` | Passed | — |
| Composer `validate --strict` | Valid | — |
| Composer `audit` | No security vulnerability advisories | — |
| Compose `config --quiet`; Git diff whitespace | Passed | — |
| Fresh dependency installation; `composer check-platform-reqs` | Passed on PHP **8.5.11**, PhpRedis **6.3.0** | — |
| Fresh migrations, sample seeders, config-cache health, event/schedule discovery | Passed; 17 migrations; health 200 with cached config | — |

The Redis-enabled contention rerun used a temporary PHPUnit XML **inside the API container's `/tmp`**, preserving all guards and production thresholds, setting only catalogue caching on with a dedicated namespace. It ran directly through `vendor/bin/pest --configuration=/tmp/ecommerce-7e-cache-concurrency.xml --compact` against the five files listed in README. Its single generation key was inspected and removed by exact key. Other suites used the unchanged committed `phpunit.xml`. No parallel suites ran against the same database; the two full suites used separate Compose databases/Redis servers.

Final Redis admission results: public limit 3 yielded **3×200 / 5×429**; two customers each received **2×200 / 2×429**; shared-customer/key checkout yielded **1×201 / 1×200 / 6×429**, one order/redemption/outbox event. Each scenario confirmed eight unique PHP PIDs and eight unique Redis connections. Public Redis IDs were **8780/8783/8779/8785/8781/8784/8778/8782**; checkout IDs were **8797/8803/8796/8801/8799/8800/8798/8802**. Remaining headers never went negative. Cleanup retained sentinel keys outside the owned namespaces.

## 9. API smoke-test evidence

Executed with **Python standard-library urllib over actual TCP**, against a fresh local clone at port **18091**, Compose project `ecommerce-final-review-7e`. This was separate from the kernel-based Pest tests. Only optional seed data and disposable users/API-created product/promotion records were used. No development records or private development environment were copied.

| Journey | Actual outcome |
| --- | --- |
| Register customer, admin candidate, second customer; login | 201 registrations, 200 logins; submitted `is_admin` did not grant privilege; customer admin access 403 |
| Grant existing disposable administrator with local-only Artisan command | Successful; no public admin registration |
| Browse/detail/search/filter/sort/paginate products | 200; combined exact-price/name/availability query selected the new product |
| All eight admin endpoints | Product and promotion list/detail/create/PATCH succeeded; product stock adjustment was additive |
| Cart add/update and reject excessive quantity | 201/200; excessive quantity 409; cart stayed valid |
| Apply promotion, retrieve estimate | 200; exact capped amounts in section 7 |
| Checkout with key, repeat same key, inspect cleared cart and stock | 201 then identical 200 replay; one order; stock 10→7; empty cart/cleared promotion |
| History/detail and foreign-order access | 200/200; second customer 404 |
| Cancel and repeat cancellation | 200/200, identical persisted markers; stock restored once to 10 |
| Verify usage, edit catalogue, inspect historical snapshot | Redemption count remained 1; customer coupon reuse returned 409; snapshot retained original name/price; separate +2 adjustment left stock 12 |
| Delete new cart line, remove promotion, logout/reuse token | 204/204/204; revoked token returned 401 |
| Default catalogue admission boundary | 120 total successful catalogue requests, next 429; limit 120, remaining 0, positive Retry-After **58 seconds**, matching request ID |
| Relay and process placement/cancellation | Claimed/dispatched **2**; both processed once; **2** notifications, **0** failed jobs |
| Stop only disposable Redis, then restart | `/api/health` and catalogue returned sanitized **503**; `/up` remained **200**; recovered API health **200** |

There were **44 recorded journey steps**, plus the default-limit request loop, two queue jobs, and four outage/recovery checks. Smoke identifiers were product **7**, promotion **8**, order **1** in the disposable database. No tokens/passwords are included in this report or evidence. The disposable worker/scheduler profiles also started successfully, and were stopped before cleanup.

## 10. Postman verification

The committed collection parses as JSON and declares Postman v2.1. Structural checks covered request methods/URLs, folders, variable declarations, and raw bodies. **27 requests** map to all **25 non-HEAD API route entries**; all variable references resolve. **11 raw bodies** parsed after substituting safe template values; **28 JavaScript event scripts** compiled using Node's VM parser. Credential/token defaults are empty.

**Newman was unavailable**, and neither Newman nor a schema-validator dependency was installed. A full official JSON-schema validation and Postman/Newman sandbox execution were **not performed**. Script compilation is not execution of `pm` assertions. The TCP journey independently exercises the API behavior and all collection endpoint categories. Detailed contracts/examples remain in [07-api-contracts.md](07-api-contracts.md).

## 11. Reproducible setup verification

A fresh **local Git clone of the committed release branch**, with no copied vendor directory or private `.env`, successfully performed the README build/install/key/migrate/seed/start sequence. PostgreSQL and Redis belonged to a new Compose project/network/volume; no connection to `postgres-local` was used. The Docker build reused valid layer cache; it was not a no-cache build, and cloning from GitHub itself was not tested.

Rehearsal adaptations were only the project name, API port/APP_URL, and `APP_DEBUG=false` in the clone's disposable environment. Locked Composer dependencies installed without changes; all platform requirements passed. Runtime versions observed were PHP **8.5.11**, PostgreSQL **17.11**, Redis **7.4.11**, PhpRedis **6.3.0**, Laravel **13.34.0**, Sanctum **4.3.3**, Pest **4.7.8**, PHPUnit **12.5.33**, Pint **1.32.1**. No Node build is needed for the API.

All **17 migrations** ran on a newly created volume. Explicit ProductSeeder/PromotionSeeder supplied **six products/seven promotions**. The default demo-customer seeder remains intentionally outside the documented setup. The fresh test database was created by the initialization SQL; the complete 874-test suite ran successfully there. APP_KEY generation and config-cache startup both succeeded. Event discovery mapped both order events to the listener, and schedule discovery showed the ten-second relay. Optional worker/scheduler services started; a direct bounded worker processed real events.

Rehearsal commands used `docker compose -p ecommerce-final-review-7e` in the clone, with the same commands documented in README; optional profiles used `--profile orders`. The project was removed with that exact project name and `down --volumes`, removing only its new containers/network/volume. Its private generated environment was deleted. Temporary verification files were outside the repository. The original Compose services remained running and healthy; read-only development counts before/after remained products/orders/redemptions/outbox/notifications **0/0/0/0/0**. Neither a development reset nor an unrelated Docker mutation occurred.

README already explains PHP/Composer requirements, APP_KEY, environment precedence, PostgreSQL/test provisioning, Redis setup, queue workers, architecture, concurrency and limitations. Here its current-audit links, general-cache description and release state were corrected. The roadmap now records the 7D commit and 7E branch accurately.

## 12. Remaining limitations

- The original employer brief is absent; unseen requirements cannot be certified.
- No Newman execution, complete Postman schema validation, static analyzer, external HTTP security scan, or production load test was performed. No new dependencies were authorized or installed.
- A dedicated simultaneous per-customer-only promotion-limit race is not isolated by the harness; the functional and serialization evidence is stated separately above.
- Cache invalidation has a commit-to-callback failure window and bounded TTL staleness. Fixed limiter windows permit boundary bursts; eviction/restart resets counters. The native adapter must be reviewed on upgrades and must retain pre-execution policies.
- Local Redis is shared/disposable; PostgreSQL outbox recovery tolerates lost queue messages, but durable external delivery, strict limiter availability and production operations are outside this assessment runtime.
- One currency/cart/promotion, no stock reservation, no payment/refund/shipping/tax features; only placed/cancelled order states. Indefinite tokens, privileged SQL bypass, retention policy, substring-search scaling and JavaScript bigint handling remain documented trade-offs.
- No speculative runtime refactor or test-count expansion was made. These limits are not reported as corrected features.

## 13. Submission readiness assessment

**Ready for final review and Git submission against the supplied requirements, subject to approval of the uncommitted documentation.** No implementation/test blocker remains. Reconcile the original brief if it becomes available. A GitHub remote is not configured in this working repository, so the repository URL is still needed for publication. Nothing has been pushed or submitted.

Recommended final commit message: **`docs: prepare final ecommerce assessment submission`**.

After explicit approval, review and execute these commands from the project root. Replace the GitHub URL placeholders with the intended repository. They publish the release branch; they do not merge main.

```bash
git switch release/ecommerce-assessment-final
git diff -- README.md docs/10-implementation-roadmap.md
git diff --no-index -- /dev/null docs/18-final-submission-review.md
# The preceding no-index review returns 1 when the new report has differences.
git add -- README.md docs/10-implementation-roadmap.md docs/18-final-submission-review.md
git diff --cached --check
git diff --cached --stat
git commit -m "docs: prepare final ecommerce assessment submission"
git remote add origin 'https://github.com/<owner>/<repository>.git'
git push -u origin release/ecommerce-assessment-final
git status --short
```

If a remote is configured later, inspect `git remote -v` and use that intended remote instead of adding it again. No authenticated URL/token belongs in the commit or report. Merging main, creating a submission PR, or sending an employer message is a separate approved action. The final report and README are the reviewer entry points; no release review commit is made in this milestone.

## 14. Git branch history and inventory

| Commit | Preserved milestone |
| --- | --- |
| `4d10dfc` | Foundation/authentication |
| `f84fefb` | Product catalogue |
| `5307457` | Cart/promotions |
| `f4d7921` | Transactional checkout/order creation, idempotency |
| `0e3a39c` | Order management/cancellation |
| `fae4fcc` | Admin APIs; unchanged `main` |
| `c11fee5` | Redis catalogue caching |
| `49732ad` | Transactional outbox/Redis queues |
| `a21aab3` | API rate limiting; completed 7D and release starting commit |

`git merge-base --is-ancestor` succeeded for all eight preceding milestones. The release starting commit/HEAD is **`a21aab30064583e820c59dbb9c4bf9d5cca3fbfd`**; its parent is **`49732ad851d8163e47b91be2979a0ffda8cc974d`**. `main` stays at **`fae4fcc7dfe50e798b04107b465903326e665756`**. No previous commits were rewritten.

7D committed **31 files, 1,113 insertions, 58 deletions**. All pre-existing worktree changes were reviewed as 7D-related. Exact inventory:

```text
.env.example
README.md
app/Http/Middleware/ThrottleApiRequests.php
app/Http/RateLimiting/ApiRateLimiters.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
config/database.php
config/rate-limits.php
config/trustedproxy.php
docs/05-architecture.md
docs/07-api-contracts.md
docs/08-business-rules.md
docs/09-testing-strategy.md
docs/10-implementation-roadmap.md
docs/17-api-rate-limiting.md
phpunit.xml
postman/Ecommerce_Order_Promotion_API.postman_collection.json
routes/api.php
tests/Feature/Http/Controllers/Api/AuthControllerTest.php
tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php
tests/Feature/Http/Middleware/ThrottleApiRequestsTest.php
tests/Feature/Services/Admin/AdminConcurrencyTest.php
tests/Feature/Services/Cart/CartConcurrencyTest.php
tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php
tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php
tests/Feature/Services/Order/OrderConcurrencyTest.php
tests/Feature/Services/Product/ProductCatalogueCacheTest.php
tests/Fixtures/cart-promotion-request.php
tests/Fixtures/cart-request.php
tests/Fixtures/rate-limit-request.php
tests/TestCase.php
```

Final verified working-tree state on **`release/ecommerce-assessment-final`** (empty index):

```text
 M README.md
 M docs/10-implementation-roadmap.md
?? docs/18-final-submission-review.md
```

## 15. Interview talking points

1. Explain the stock-5/quantities-4-and-3 race using row locks, Read Committed revalidation, guarded updates and a rollback of the loser.
2. Explain deterministic lock order and bounded whole-transaction retries; distinguish an observed contention test from an injected deadlock.
3. Walk through integer minor units/basis points, overflow-safe half-up rounding, caps and immutable purchase snapshots.
4. Explain why cart estimates reserve neither stock nor coupons and why promotion counting/insertion requires the same locked row.
5. Explain customer-scoped successful idempotency keys, failed attempts, replay after cart refill/cancellation and the 201/200 contract.
6. Explain cancellation's order lock, restoration markers, overflow handling and retained coupon usage.
7. Explain cache generations, after-commit invalidation, stale-reader protection, TTL failure windows and PostgreSQL authority.
8. Explain the transactional outbox's commit guarantee, ownership/leases, Redis loss, retries and effectively-once local effects versus external delivery.
9. Demonstrate the installed native limiter's check/acquire race, the narrow adapter, atomic admission, truthful headers and fail-closed 503.
10. Explain admin privilege boundaries, token policies, proxy trust, logical Redis isolation and the limits of this development deployment.
11. Use the evidence matrices to separate implemented behavior, executed checks and unverified production/runner scenarios.

**Stop condition:** verification and documentation completed; release review changes remain uncommitted. No merge, push, deployment or employer submission is authorized by this report.
