# 13 — Independent Technical Audit & Submission Readiness

Audit date: October 6, 2026 (Africa/Cairo). Deadline: October 13, 2026. Project: `/var/www/ecommerce-order-api`. Starting commit: `0e3a39c`.

## 1. Executive assessment

**Ready for final technical review after the corrections below.** No confirmed critical security, monetary, overselling, duplicate restoration, or atomicity defect was found in the supported application paths. The recorded functional requirements are implemented after closing FR-P03. This is an assessment API, not a production deployment approval.

The original employer brief is not separately available. `docs/02-requirements.md`, the other design documents, and the user's Milestone 7 checklist are the available baseline. Consequently, this audit cannot unconditionally certify every requirement of an unseen employer document. Reconcile the original brief before submission. The recorded NFR-04 prohibition on unbounded scans also cannot be certified: substring search and ledger counting can scan growing matching datasets. Bounded responses and query counts are verified; production-scale latency is not.

The starting tree was clean, with no modified/untracked files. History was inspected: `0e3a39c` orders/cancellation, `f4d7921` checkout, `5307457` cart/promotions, `f84fefb` catalogue, and `4d10dfc` foundation/authentication. README, design documents 01–10, checkout/order reports, Docker/env configuration, all migrations, domain services/contracts/repositories, HTTP boundaries/resources/policies, and automated/process tests were reviewed independently. Earlier milestone reports were treated as historical claims, not evidence of current success.

Installed versions were checked with `composer show --direct` and Boost: PHP 8.5.11 in Docker, Laravel 13.34.0, Sanctum 4.3.3, Pest 4.7.8, PHPUnit 12.5.33, Pint 1.32.1, PostgreSQL 17.11. Laravel/testing skills and version-specific Boost documentation were used. `.ai/rules` is absent. Boost database tools lacked the host PDO driver; actual schema, constraints, indexes, and target identity were inspected read-only through the project PostgreSQL container.

**Finding disposition:** 0 confirmed CRITICAL; 1 HIGH corrected; 3 MEDIUM corrected; 1 LOW hardening correction. Two MEDIUM verification limits and additional LOW/operational trade-offs remain explicitly documented. No architectural replacement, dependency change, bonus feature, migration rewrite, commit, push, or deployment was performed.

## 2. Requirements compliance matrix

Verification labels: **PG** = source inspection plus passing real PostgreSQL HTTP/service/schema tests; **U** = independent pure calculation tests; **C** = observed contention using separate processes/connections; **FI** = fault injection/partial mocks for the stated defensive branch; **R** = fresh isolated setup rehearsal; **A** = artifact validation; **Limited** = evidence does not establish the stronger claim. Passing tests are not, by themselves, proof of arbitrary load behavior.

Implementation abbreviations below refer to these actual files:

- **Product**: `app/Services/Product/ProductService.php`, `app/Repositories/Eloquent/EloquentProductRepository.php`, `app/Http/Requests/Product/ProductQueryRequest.php`, `app/Http/Resources/ProductResource.php`.
- **Auth**: `app/Services/Auth/AuthService.php`, `app/Repositories/Eloquent/EloquentUserRepository.php`, `app/Http/Requests/Auth/*`, `config/sanctum.php`, `app/Providers/AppServiceProvider.php`.
- **Cart**: `app/Services/Cart/CartService.php`, `CartPricingService.php`, `CartPromotionService.php`, `app/Repositories/Eloquent/EloquentCartRepository.php`, `app/Http/Requests/Cart/*`, `app/Policies/CartPolicy.php`.
- **Promotion**: `app/Services/Promotion/PromotionService.php`, `PromotionCalculator.php`, `app/Repositories/Eloquent/EloquentPromotionRepository.php`.
- **Checkout**: `app/Services/Checkout/CheckoutService.php`, `app/Http/Requests/Checkout/CheckoutRequest.php`, `app/Repositories/Eloquent/EloquentOrderRepository.php`, product/cart/promotion repositories.
- **Order**: `app/Services/Order/OrderService.php`, `app/Repositories/Eloquent/EloquentOrderRepository.php`, `app/Policies/OrderPolicy.php`, `app/Http/Resources/OrderResource.php`, `OrderItemResource.php`.

Test abbreviations resolve to real test files, not mocked substitutes:

| Key | Coverage location |
|---|---|
| PC / PM / PR | `tests/Feature/Http/Controllers/Api/ProductControllerTest.php`, `tests/Feature/Models/ProductTest.php`, `tests/Feature/Repositories/Eloquent/EloquentProductRepositoryTest.php` |
| AC | `tests/Feature/Http/Controllers/Api/AuthControllerTest.php` |
| CC / CM / CS | `tests/Feature/Http/Controllers/Api/CartControllerTest.php`, `tests/Feature/Models/CartTest.php`, `tests/Feature/Services/Cart/CartServiceTest.php` |
| CPC / CPS | `tests/Feature/Http/Controllers/Api/CartPromotionControllerTest.php`, `tests/Feature/Services/Cart/CartPromotionServiceTest.php` |
| PS / PMO / PRM | `tests/Feature/Services/Promotion/PromotionServiceTest.php`, `tests/Feature/Models/PromotionTest.php`, `tests/Feature/Models/PromotionRedemptionTest.php` |
| CALC | `tests/Unit/Services/Promotion/PromotionCalculatorTest.php` |
| CHC / CHS | `tests/Feature/Http/Controllers/Api/CheckoutControllerTest.php`, `tests/Feature/Services/Checkout/CheckoutServiceTest.php` |
| OC / OS / OM / OCM | `tests/Feature/Http/Controllers/Api/OrderControllerTest.php`, `tests/Feature/Services/Order/OrderServiceTest.php`, `tests/Feature/Models/OrderTest.php`, `tests/Feature/Models/OrderCancellationTest.php` |
| CART-C / PROM-C / CHECK-C / ORDER-C | `tests/Feature/Services/Cart/CartConcurrencyTest.php`, `CartPromotionConcurrencyTest.php`, `tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php`, `tests/Feature/Services/Order/OrderConcurrencyTest.php` |

### Catalogue

| Requirement | Implementation | Relevant database constraints | Tests | Status / concerns |
|---|---|---|---|---|
| Product schema | Product model; `132432_create_products_table` migration | NOT NULL name/SKU/price/stock/status; scalar CHECKs | PM | PG; description nullable, bigint price/stock |
| SKU uniqueness | Product SKU mutator | `products_sku_normalized_unique` on lower(btrim(sku)); `products_sku_not_blank` | PM | PG; raw bypass imports still cannot duplicate case/space equivalents |
| FR-P01 product visibility | Product service and status-filtered repository | `products_status_allowed` | PC, PR | PG; inactive hidden; active zero stock visible by default |
| FR-P03 name/SKU/description search | Grouped repository whereLike/orWhereLike | No substring CHECK/index guarantee; status remains an AND condition | PC regression, PR | PG + fresh HTTP; HIGH gap fixed; literal escaping retained |
| FR-P04 price filters | Query request, ProductQuery, repository | `products_price_minor_non_negative` | PC, PR | PG; inclusive nonnegative integer bounds, min <= max |
| FR-P04 availability filters | Repository stock predicate | `products_stock_quantity_non_negative` | PC, PR | PG; positive stock vs zero stock |
| FR-P05 sorting | Request allow-list and independent repository guard | Composite status/price/name/created_at/ID indexes | PC, PR | PG; bound values, mapped identifiers; no arbitrary SQL columns |
| FR-P01 pagination | Request + repository paginator | Identity tie-breaker; no page-size DB constraint | PC | PG; default 15, maximum 100; validated filters retained in links |
| FR-P02 details | Service validated ID + active-state check; resource | Primary key, allowed status | PC | PG; inactive/missing/malformed/overflowing IDs 404 |

### Authentication

| Requirement | Implementation | Relevant database constraints | Tests | Status / concerns |
|---|---|---|---|---|
| Registration | Auth register; RegisterRequest; UserResource | `users_email_unique`, required password; unique token digest | AC | PG; password/hash/token verified; token failure rolls user back; unique-conflict branch includes FI |
| Login | Auth login + rehash + token issuance | Unique normalized application email lookup | AC | PG; wrong email/password share 401; no registration strength policy on existing passwords |
| FR-A04 logout | Auth currentAccessToken deletion | Token primary key | AC | PG; current token revoked, other tokens retained |
| FR-A01/02 protected endpoints | Named routes with auth:sanctum; bearer-only guard | Unique SHA-256 token digest | AC, CC, CPC, CHC, OC | PG; invalid/revoked/no token denied; web-session-only access denied |
| FR-A03 customer ownership | Authenticated User, scoped repositories, Cart/Order policies | Owner FKs; one cart/user | CC, OC, policy tests | PG; forged IDs cannot select another owner; foreign/missing both 404 |
| Token security | Native Sanctum; hidden password fields; allow-list resources | Unique stored token hash; nullable expires_at | AC | PG; plaintext returned only on issue; indefinite wildcard tokens are explicit single-role policy |

### Cart

| Requirement | Implementation | Relevant database constraints | Tests | Status / concerns |
|---|---|---|---|---|
| FR-C01 retrieval | Cart findForUser + pricing/resource | `carts_user_id_unique`, owner/item/product FKs | CC, CS | PG; absent cart reads without insertion; eager relationships |
| FR-C02 add | Cart addItem; strict Form Request | Positive quantity CHECK; required product FK | CC, CS | PG; active product, current server price, ignores forged money/identity |
| FR-C03 merge | Locked cart + existing product-line lookup | `cart_items_cart_id_product_id_unique` | CC, CART-C | PG + C; no duplicate line or lost accumulated quantity |
| FR-C04 quantity update | Cart updateItem | `cart_items_quantity_positive` | CC, CS | PG; replaces quantity; does not reassign product/owner |
| FR-C05 remove | Cart removeItem | Owned cart FK; product deletion restricted | CC | PG; unavailable lines removable; 204; no stock restoration |
| FR-C06 stock validation | Locked product; subtraction-safe accumulated check | Product stock >=0; quantity >0 | CC, CS, CART-C | PG + C for additions; current stock rechecked under lock |
| Concurrent modifications | Cart-owned transaction; createOrLockForUser | Unique cart/customer and line/product; ON CONFLICT DO NOTHING | CART-C, PROM-C | C for concurrent additions/selections; PATCH-vs-DELETE contention not separately exercised |
| No reservation | Cart repositories never update inventory | No reservation columns/table | CC, CART-C, PROM-C | PG + C; different customers can cart more than combined available stock |
| FR-C07 attach/remove code | CartPromotion apply/remove | Nullable promotion FK ON DELETE SET NULL | CPC, CPS, PROM-C | PG + C; no usage consumed; one selected code |

### Promotions

| Requirement | Implementation | Relevant database constraints | Tests | Status / concerns |
|---|---|---|---|---|
| FR-R01 percentage | Calculator + promotion enum/model | `promotions_type_allowed`, `promotions_value_valid` (1..10000 bps) | CALC, PMO, CHC | U + PG; exact basis points |
| FR-R01 fixed | Shared calculator | Positive value CHECK | CALC, CPC, CHC | U + PG; minor units, capped/clamped |
| FR-R02 minimum spend | Promotion eligibility | `promotions_minimum_non_negative` | PS, CPC, CHC | PG; inclusive boundary |
| FR-R02 maximum discount | Calculator minimum of discount/cap/subtotal | `promotions_maximum_positive` | CALC, CPC, CHC | U + PG; optional positive cap applies to both types |
| FR-R02 validity dates | UTC eligibility; starts inclusive, expiry exclusive | `promotions_dates_ordered`; timestamptz(6) | PS, CPC, CHC | PG; offset/microsecond boundaries covered |
| FR-R02 global usage | Lock promotion, then aggregate ledger and insert in checkout | Positive limit CHECK; ledger indexes; unique order_id/redemption_key | CHC, CHS, CHECK-C | PG + C; one of two buyers consumes last global use |
| FR-R02 customer usage | Same promotion lock and owner count; cart serializes same customer | Positive customer-limit CHECK; `(promotion_id,user_id)` index | PS, CPC, CHC | PG; concurrent per-customer-only limit scenario not separately isolated; protocol reviewed |
| FR-R02 activity | Eligibility is_active decision | Non-null boolean | PS, CPC, CHC | PG; inactive selection returns safe error/zero estimate |
| Calculation correctness | Shared pricing/eligibility/calculator | Order/item/ledger amount CHECKs | CALC, CHC | U + PG; exact expected vectors; no float arithmetic |
| Code integrity | Normalize uppercase; request regex; equality lookup | `promotions_code_unique`, `promotions_code_normalized` | PMO, CPC | PG; strings bound; no wildcard SQL lookup |
| FR-R03/04 redemption revalidation/ledger | Checkout under promotion lock; createRedemption | Restricted order/customer/promotion FKs; unique order_id and UUID | CHC, CHS, OM, CHECK-C | PG + C; every production redemption call follows same protocol; legacy NULL order links retained |

### Checkout and orders

| Requirement | Implementation | Relevant database constraints | Tests | Status / concerns |
|---|---|---|---|---|
| FR-O01 cart validation | Checkout lock + loadCheckoutItems + empty rejection | One cart/user; item quantity/product FK | CHC | PG; absent/persisted empty carts give 409 without order/key state |
| FR-O02 server prices | Locked products supplied to shared pricing | Price nonnegative, item exact amounts | CHC | PG; client prices/totals ignored; current price authoritative |
| Stock revalidation | Locked active products + conditional deduction | Product status and nonnegative stock CHECKs | CHC, CHS, CHECK-C | PG + C; no negative inventory |
| Promotion revalidation | Fresh eligibility/count after promotion lock | Promotion scalar constraints; ledger uniqueness | CHC, CHS, CHECK-C | PG + C for global limit; customer-specific contention evidence limited as above |
| Correct totals | CartPricingService + calculator + order snapshot writer | `orders_money_valid`, `order_items_money_valid` | CALC, CHC, OM | U + PG; persisted total = subtotal − discount; aggregate item sum service-owned |
| FR-O03/04 atomic transaction | Checkout service DB::transaction, attempts 3 | All writes same connection; FKs/CHECKs final defense | CHS, CHECK-C | PG + FI; write-boundary rollback and injected retry/exhaustion; actual contention separate |
| Inventory deduction | Product repository conditional decrement | `products_stock_quantity_non_negative` | CHS, OM, CHECK-C | PG + C; late failure rolls earlier deduction back |
| Order creation | Order repository create + createMany items | Owner FK, status/currency/money/snapshot CHECKs | CHC, OM | PG; one purchase outcome |
| FR-O06 immutable snapshots | Order/item stored fields; resource has no live catalogue reads | Item money/quantity constraints; restricted purchase-history FKs | CHC, OC, OM | PG; names/SKUs/prices/currency/promotion stay historical after edits; no DB immutability trigger |
| Cart clearing | Repository clear in same transaction | Cart/line FK and one-cart identity retained | CHC, CHS | PG; selection/items removed only after success; rollback restores all |
| Concurrent identical keys | Cart lock; owner-scoped replay before eligibility | `orders_user_id_idempotency_key_unique` | CHC, OM, CHECK-C | PG + C; 201/200 same order; one stock deduction and redemption |
| Key scope/conflicting reuse | CheckoutRequest header; permanent original-order association | Key format CHECK + customer/key uniqueness | CHC, OM | PG; old key with refilled cart returns original order; new cart untouched; no alternate purchase payload contract |
| Replay after cancellation | Replay loads stored order/items | Retained unique key/order history | OC | PG; cancelled order returned, no stock/coupon use |
| Failure leaves no key state | Key is stored only on successful order in transaction | Transaction rollback; no separate key reservation table | CHS, CHECK-C | PG + FI; same key can retry a failed transaction |
| FR-O05 history | Owner-scoped repository pagination; summaries omit items | `(user_id,created_at,id)` index | OC, OS | PG; 15 default/100 maximum, two queries |
| FR-O05 detail | Scoped find + policy + eager items | Owner/item FKs | OC, OS | PG; two queries; no live catalogue query |
| Ownership protection | Scoped service lookup and OrderPolicy | Owner FK | OC, policy tests | PG; foreign/missing IDs indistinguishable 404 |
| FR-O07 cancellation eligibility | Locked order; placed only | `orders_status_valid`, `orders_cancellation_state_valid` | OC, OS, OCM | PG; corrupt/unsupported defensive states use FI because CHECKs prohibit ordinary fixtures |
| FR-O07/08 exactly-once restoration | Order lock → ascending products; conditional increment | Item uniqueness/quantity; stock nonnegative; coherent markers | OC, OS, OCM, ORDER-C | PG + C; one transition/increment; rollback all products on failure |
| Stable repeated cancellation | Already-cancelled locked row returned | Equal non-null timestamptz markers | OC, ORDER-C | PG + C; no second product lock/write or timestamp change |
| FR-O09 usage remains consumed | Cancellation never touches ledger/discounts | Restricted redemption order FK | OC | PG; original usage still counts; no refund/re-credit feature |

### Technical deliverables and non-functional requirements

| Requirement | Implementation | Relevant constraints / protections | Coverage | Status / concerns |
|---|---|---|---|---|
| Git-ready repository | Locked source; ignore files; staged/unstaged review | Secrets/vendor/logs/caches excluded; env variants now excluded | Git inventory/diff/history inspection | A; changes deliberately uncommitted; no secret found by scoped review (not an exhaustive secret scanner) |
| README / setup | Revised README; Compose; Dockerfile; .env.example | Separate project network/volume, no DB host port | Fresh archive + final PHP changes, new vendor install | R; PHP lock requirement corrected; default fixed customer seeder explicitly avoided |
| Architecture decisions | docs/05; transaction reports; this audit | Approved Service + Repository preserved | Source + repository binding/endpoint tests | PG/A; native Sanctum persistence exception already documented |
| Migrations | 14 committed migrations | Real FKs, expression/ordinary unique indexes, CHECKs | Model tests + fresh empty database migrate | PG + R; all 14 applied; downgrade refusal verified |
| Factories and seeders | Factories for all domain entities; ProductSeeder/PromotionSeeder | Sample SKU/code uniqueness | Seeder tests + isolated seed rehearsal | PG + R; six products/seven promotions; default customer seeder not repeatable |
| API docs or Postman | docs/07 + new Postman v2.1 collection | Empty credential/token defaults; inherited bearer auth | JSON/payload/route/JS syntax validation | A; 18 requests cover all 17 routes; Postman execution not performed |
| NFR-01 concurrent correctness | Supported cart/checkout/order locks | Nonnegative stock; key/redemption uniqueness | Four concurrency files | C for specified scenarios; not universal scheduling proof |
| NFR-02 consistency | Service transactions; no repository commit | PostgreSQL ACID and one default connection | CHS, OS, real rollback/constraint tests | PG; no external irreversible effects within transactions |
| NFR-03 security | Sanctum, policies, requests, limiter, resources | Password/token hashing; DB uniqueness | Authentication/ownership/injection/forged-field tests | PG; auth throttles only; indefinite token policy acknowledged |
| NFR-04 performance | Pagination, eager loading, lookup/history indexes | Bounded page sizes and query counts | CC/CPC/OS query counts; index inspection | Limited; substring/count scans and full cart/detail size can grow; no representative benchmark |
| NFR-05 maintainability | Typed services/contracts/enums/DTOs; formatter | Constructor DI; no generic repository | Source review, bindings, Pint | Verified; no cycle/unnecessary refactor identified |
| NFR-06 observability | ApiRequestContext, exception rendering, logging config | Generated request ID; no bodies/tokens logged by app code | ApiErrorResponseTest, HTTP tests; source | PG/source; default text logs carry context, not a JSON log ingestion guarantee |
| NFR-07 portability | README + Docker/locked dependencies | Isolated test configuration and target guard | Fresh setup/platform/HTTP checks | R; native non-Compose tests are deliberately unsupported |
| NFR-08 testability | Pest, real DB, pure calculator, process harness | Guarded test environment/database | Full suites and standalone harness | PG/U/C; FI branch evidence explicitly separated |
| NFR-09 API stability | Resources/central error envelope/named routes | Validated input, hidden private fields | Controller/error contract tests; Postman route check | PG/A; search expanded only to satisfy recorded FR-P03 |
| NFR-10 recovery | Full workflow rollback and bounded retries | Database transactions, conditional inventory updates | CHS, OS, CHECK-C/ORDER-C injections | PG + FI; failures leave no partial purchase/cancellation |

## 3. Architecture review

Thin controllers delegate validated input, authenticated identity, and business operations. Form Requests handle mutable request input; ID parsing lives in services to preserve the existing non-enumerating 404 contract. Services depend on repository interfaces, own the business rules and workflow transactions, and share CartPricingService/PromotionCalculator/PromotionService. The five repository interfaces are bound in AppServiceProvider; real endpoint/binding tests exercise resolution. The dependency graph is acyclic.

Eloquent repositories encapsulate persistence, owner scoping, pagination/eager loading, and lock mechanics. None starts or independently commits the multi-repository checkout/cancellation transaction. AuthService uses native Sanctum createToken/currentAccessToken deletion directly; `docs/05-architecture.md` already approves this framework exception. Moving it solely to make the diagram literal would add churn without a demonstrated defect.

No accidental N+1 was found in critical routes. Cart reads eager-load items/products/promotion; absent promotion uses three cart/item/product queries. Selected-promotion estimates add a bounded promotion/count query. Order list uses count + orders, omits items; details load order + items with no live product/promotion queries. Checkout holds one ordered product-set query and uses preloaded locked relations. Writes scale with line count, as expected. Full carts/order detail are not size-bounded; no large-data throughput guarantee is claimed.

## 4. Security review

No confirmed authorization, injection, token-at-rest, mass-assignment, or API error disclosure vulnerability was found. Policies and owner-scoped queries work together. Checkout/cancellation accept no client owner, inventory, money, status, or timestamp decisions. Resource allow-lists hide password/remember/token/idempotency/ledger internals; order user_id is the authenticated owner's public identifier.

Search values are bound and SQL wildcard characters escaped. The widened OR search is grouped so an inactive SKU/description match cannot bypass visibility or stock/price filters. Sorting is validated in the request and separately mapped in the repository. Coupon codes normalize/validate before equality lookup. Quantity validation is strict for JSON body integers.

Sanctum uses bearer authentication with `guard=[]`, not a web-session fallback. Passwords are explicitly hashed (including hash-looking input), null bytes rejected, and bcrypt byte limits enforced. Logout tests confirm revocation and device isolation. Login/register errors are sanitized and rate limits cover normalized account/IP and IP rotation. Tests use array cache/time control; actual contention of database-backed authentication limiter counters was not tested.

Unexpected API errors remain generic even when debug is enabled, while server-side reporting retains diagnostics. X-Request-ID matches the error envelope; API responses are non-cacheable. Application code does not log request bodies, credentials, or tokens, and sensitive auth arguments are marked SensitiveParameter. Production debug=false, TLS, logging access controls, and managed secrets remain deployment responsibilities. No production configuration or penetration/load test was executed.

Tracked files/history-path review found only the local example environment, documented local-only Compose credentials, and synthetic test data; no real credentials/tokens were found. `.gitignore` and `.dockerignore` now exclude environment variants while retaining `.env.example`. Existing vendor, node_modules, logs, caches and IDE exclusions were inspected. This review is not a claim that an automated exhaustive secret scanner ran.

## 5. Financial correctness

All purchase money uses integer minor units within signed bigint / PHP_INT_MAX on 64-bit PHP. No money computation uses floating-point arithmetic. The calculator splits subtotal into quotient/remainder before multiplying basis points; the remainder product plus half-up rounding constant stays below 100 million. With 1..10000 basis points the whole part is bounded by subtotal, and addition is checked. Caps and subtotal clamping occur after deterministic rounding. Fixed discounts are clamped identically. The final subtraction cannot be negative.

CartPricingService guards price × quantity using integer division, then guards subtotal addition using subtraction. Cart addition similarly checks available stock by subtraction before accumulating quantity. Restoration checks stock <= PHP_INT_MAX − purchased quantity before any addition, and repeats that bound in the SQL update. Checkout uses the same pricing/calculator as cart estimates after reading locked current prices.

Existing exact vectors cover one basis point/20%/100% at PHP_INT_MAX, values beyond IEEE-754 exact integers, fractional half-up boundaries, caps, fixed discounts exceeding subtotal, zero subtotal/price, invalid percentages/negative inputs, multiplication/sum overflow, minimum-spend/date boundaries, and maximum restoration. Expected values are supplied independently, not computed by the production calculator in assertions.

Orders CHECK nonnegative components, discount <= subtotal, and total = subtotal − discount. Item CHECK uses exact PostgreSQL numeric operands to verify quantity × unit price without overflowing CHECK evaluation. Service tests verify order subtotal equals item sum; no cross-table aggregate trigger exists. Historic product/promotion/currency edits do not change stored order snapshots or responses. Privileged SQL can still change snapshots or write inconsistent cross-table aggregates; supported writers must use the services. JavaScript consumers need lossless JSON handling above 2^53−1; the API does not silently convert money to floats.

No confirmed calculation defect required a production correction.

## 6. Concurrency verification

### Protocol and lock-order review

| Operation | Acquired locks / relationship behavior |
|---|---|
| Cart add/update | Cart first, then affected Product; first-cart uniqueness uses ON CONFLICT DO NOTHING then locked lookup |
| Cart delete | Cart first, then owned line deletion; no inventory change |
| Promotion apply/remove | Cart first; products/eligibility read as estimates; FK association may take the normal promotion key-share lock; no redemption |
| Checkout | Cart → all Products ascending ID → selected Promotion; subsequent counts/ledger writes remain in that transaction |
| Cancellation | Owned Order → all Products ascending ID; no Cart/Promotion acquisition or ledger mutation |
| Checkout replay | Cart lock then plain owner/key order lookup; no existing-order FOR UPDATE acquisition |

Sorted product acquisition is shared. Non-locking relationship/ledger reads under Read Committed do not acquire reverse product/cart row locks. Promotion selection's FK check does not lead back to another cart/product lock. Cancellation never introduces a Promotion → Cart or Product → Cart edge. No supported-operation lock inversion was found. This is source analysis plus specified overlap tests, not a proof against privileged SQL or every possible future writer. Three whole-transaction attempts handle detected concurrency failures; four deadlock tests inject server SQLSTATE 40P01 and must not be described as real deadlock cycles.

Inventory safety combines locked revalidation, conditional decrement, stock CHECK, and full rollback. Coupon consumption locks the promotion before global/customer counts and retains it until ledger insert/commit. Under tested Read Committed a waiting redeemer's later count sees the prior commit. Repository createRedemption explicitly documents the caller lock obligation; CheckoutService is the only production caller. Factories/tests can write ledgers outside the protocol to construct fixtures; no API/admin/import writer does so. Raw SQL/future writers must honor it.

Idempotency is tied to persisted orders, scoped by customer, and serialized by the cart. Old keys permanently return their original order even after cancellation/refill; this is the approved conflicting-reuse behavior because checkout has no accepted purchase body. Failed transactions leave neither a key reservation nor partial purchase. Cancellation checks eligibility under the order lock, restores all snapshots and writes status/markers in the same transaction; a waiting repeat sees cancelled and performs no further restore. Promotion usage remains consumed.

### Harness quality and observed standalone run

Committed fixtures use DatabaseMigrations rather than a transaction invisible to workers. Each worker is a separate PHP process with its own PostgreSQL PID, authenticated HTTP kernel request, guarded database target, application_name, bounded timeout, and credentials on stdin. An independent observer requires **two distinct active lock waiters before releasing the parent barrier**. The wait queries must name the relevant locked table; final state, loser cart, order/ledger counts, sorted locking SQL, and transition/increment telemetry are asserted. Polling sleeps observe state rather than assume concurrency. Cleanup releases locks/stops workers; cancelled order fixtures are removed only in the guarded test database before the intentionally restrictive downgrade.

The 18 cases comprise **14 real overlapping request cases and 4 injected retry/exhaustion cases**. Of the 14, six cover cart additions/selection/removal and eight cover checkout/cancellation. Final standalone evidence before documentation packaging:

| Scenario | Worker PostgreSQL PIDs | HTTP results | Committed invariant |
|---|---|---|---|
| Inventory 5; buyers 4 / 3 | 31737 / 31738 | 201 / 409 | One order; stock 1; losing cart intact |
| Last global coupon; distinct products/customers | 31743 / 31742 | 409 / 201 | One redemption/order; stocks 5 / 2 |
| Same customer/key | 31747 / 31748 | 201 / 200 | Same order; one redemption; stock 3 |
| Reverse-insertion shared products | 31752 / 31753 | 201 / 201 | Two orders; stocks 5 / 5 |
| Same owned order, cancel twice | 31761 / 31762 | 200 / 200 | One status update/restore; stock 10 |
| Cancellation vs buyer, sufficient stock either way | 31766 / 31767 | 200 / 201 | One restoration; final stock 6 |
| Buyer requires restoration | 31771 / 31772 | 200 / 201 | Final stock 2; buyer-first 409 is also a valid tested schedule |
| Reverse-insertion cancellation vs checkout | 31776 / 31777 | 200 / 201 | Two restores, one order update; stocks 5 / 6 |

The stock-available case previously permitted an incorrect 409; its assertion now requires 201 when enough stock exists before restoration. The restoration-dependent case retains schedule-aware outcomes. A distinct concurrent per-customer-only coupon-limit scenario, every cross-operation combination, high load, fairness/starvation, and real deadlock-cycle induction are **not** verified. Per-customer enforcement has real sequential PostgreSQL coverage plus the reviewed common promotion/cart serialization protocol.

## 7. Database integrity and migrations

Actual development schema inspection found 60 table constraints and 27 indexes across the seven commerce tables (including primary/unique indexes); the normalized SKU expression index was inspected separately from pg_constraint. Domain migrations enforce required fields, normalized SKU/code uniqueness, one cart/customer, one product/cart and order, positive quantities, nonnegative stock/money, legal promotion inputs/date ranges, legal order states/currency/keys, correct component arithmetic, and coherent cancellation markers.

Referential behavior is deliberate: deleting a customer/cart cascades disposable carts/items; referenced products, purchase customers, orders, and redeemed promotions are restricted to preserve history. Deleting an unredeemed selected promotion sets the cart selection NULL. Ledger UUID and non-null order_id are unique. Nullable legacy order_id remains database-valid and counts against limits; supported checkout always writes a persisted real order. The database does not independently verify that a ledger's customer/promotion equals the order's fields; the supported repository writer does.

Default framework session/token polymorphic references do not have strict user FKs; they are not the commerce history boundary. No account-deletion API is implemented. The order creation-date history index matches actual pagination; a placed-date index remains from earlier work and may be redundant for current routes. No migration/index was removed without measured need.

Fresh source was installed in a separate `ecommerce-audit-m7` Compose project with its own containers/network/volume and port 18091. Before migration, PostgreSQL identity was verified and public table count was zero. All **14 migrations** applied successfully using ordinary migrate, and the existing database upgrade/constraint/downgrade tests passed. No migrate:fresh/reset/rollback was run against the working development database; no development seed/data writes occurred. The isolated project was disposed after rehearsal; the original development/test databases and postgres-local were preserved.

The cancellation migration's down() attempts to restore the placed-only CHECK in the migration transaction. Cancelled rows cause refusal before the schema downgrade commits; tests prove cancellation history/markers remain intact. This is an honest documented limitation: earlier schema cannot represent restored cancelled inventory. Use forward fixes for retained deployments; do not relabel cancelled orders or delete history merely to downgrade.

## 8. Automated test quality

The suite has real PostgreSQL HTTP/repository/model tests, independent unit vectors, owned/foreign resource matrices, strict input/injection cases, precise persisted values, query-count assertions, migration upgrades, failure rollback, and genuinely synchronized process tests. Normal flows do not rely solely on mocked persistence. Partial repository mocks are limited to defensive branches such as missing FK-protected locked products/promotions, a foreign row supplied by a faulty repository, conditional update refusal, and corrupt state that PostgreSQL would normally reject. Those tests establish service defenses under FI, not ordinary database behavior. Real constraints and real database errors separately exercise rollback/conflict mapping.

Checkout failures are injected after order/item creation, deductions, redemption insertion, and cart deletion. Cancellation tests inject a later restore failure, pre/post marker saving, real unexpected PostgreSQL errors, overflow, and a real CHECK failure. They assert full original inventory/order/cart/usage state, not merely status codes. Injected deadlock cases check all attempts and absence of duplicated writes.

One existing name-only contract test was revised to the recorded FR-P03 requirement, one focused grouped-filter regression was added, and the wildcard fixture was made deterministic because random SKU digits could legitimately match search `0` after broadening. Both search regressions failed before the repository correction. One existing concurrency assertion was tightened; no test was removed. The count rose from 561 to 562 rather than expanding the suite for its own sake. Boilerplate example tests and some repetitive boundary coverage remain LOW polish items; deleting/restructuring them was unnecessary.

Assertion totals can differ by one between successful runs because the restoration-dependent contention outcome executes an extra stock-error assertion in its legitimate buyer-first branch. Reported counts below are exact observed runs, not promised constants. Five-second observer deadlines/15-second worker timeouts can fail under an overloaded host; no flake was observed in the final executions.

**Larastan assessment:** useful as a future optional guard for inferred Eloquent/collection types, but not installed/configured or run. Adding it now changes development dependencies and would require approval under project rules. Existing typed boundaries, real behavioral/constraint tests, and the small justified production correction do not warrant a new analyzer plus unrelated refactoring in this milestone. PHP syntax/behavior was exercised by the full suite; this is not a static-analysis result.

## 9. Confirmed defects and corrections

| ID | Severity | Problem / impact | Small correction / evidence |
|---|---|---|---|
| F-01 | HIGH | Recorded FR-P03 name/SKU/description requirement was deferred; SKU/description matches were missing | Grouped three-field literal search in existing product repository; regressions fail before/pass after; active/stock/price rules preserved; API/business/requirements docs aligned |
| F-02 | MEDIUM | README/discovery said PHP 8.3+ while locked Symfony 8.1 requires >=8.4.1; native locked install would fail | Correct effective prerequisite and distinguish root constraint from lock; Docker 8.5 fresh install/platform checks pass; dependencies unchanged |
| F-03 | MEDIUM | Cancellation/checkout stock-available test allowed 409 even though both schedules have enough stock | Require 201 for sufficient pre-restoration stock; retain 201/409 only for restoration-dependent case; focused/standalone/full runs pass |
| F-04 | MEDIUM | Requirements traceability still described delivered order history/cancellation as future work; README stopped at next-milestone instructions | Correct current traceability; concise reviewer README and independent evidence report; historical milestone reports retained |
| F-05 | LOW | Only selected .env variants were ignored by Git/Docker context | Ignore .env.* while retaining .env.example; verified ignore behavior; no existing secret leak identified |

New Postman collection and this report complete explicitly requested submission deliverables; their prior absence was not failure of the older API-doc-or-collection alternative. No financial/security fix was made without a confirmed defect, and approved architecture/public money/status contracts remain intact. Search broadening is the deliberate contract correction for F-01.

## 10. Remaining risks and limitations

| ID | Severity / nature | Current disposition |
|---|---|---|
| R-01 | MEDIUM verification limit | Separate employer brief unavailable; reconcile before unconditional requirements approval/submission |
| R-02 | MEDIUM performance evidence limit | NFR-04 no-unbounded-scan/latency claim not established; substring search, ledger counts, and cart/detail size grow; no representative load/query-plan benchmark |
| R-03 | Concurrency evidence limit | Per-customer-only coupon contention and every cross-operation schedule not separately isolated; supported lock protocol/source and real sequential coverage reviewed |
| R-04 | Operational policy | Indefinite wildcard customer tokens; only auth routes throttled; no production/TLS/backup/limiter-concurrency rehearsal; accepted assessment boundaries |
| R-05 | Trusted-writer boundary | Privileged SQL/future writers can bypass snapshot, cross-table aggregate/ledger, restoration, or usage-lock semantics; enforce supported service protocol |
| R-06 | LOW maintainability | Native Sanctum service persistence exception, retained placed-date index, boilerplate example tests; documented, no unnecessary refactor |
| R-07 | LOW setup limitation | Default DatabaseSeeder fixed email is not repeatable; documented sample setup uses registration plus repeatable ProductSeeder/PromotionSeeder |
| R-08 | Explicit business limits | Single currency/cart/promotion; no reservation/payment/refunds/shipping/admin/email recovery; only placed/cancelled; account retention policy undecided |
| R-09 | Tooling evidence limit | No Larastan, automated exhaustive secret scan, or Postman/Newman execution; JSON/route/payload/script validation performed |

No unresolved confirmed CRITICAL/HIGH application defect remains. R-01 is the remaining requirement-signoff blocker; R-02 prevents a stronger performance certification. Git packaging/reviewer acceptance still require a human final review. These are not silently converted into successful verifications.

## 11. Reproducibility assessment and API surface

The rehearsal used a temporary archive of committed source plus the final PHP correction/tests, a new vendor directory (no reused project vendor), ordinary Composer install/package discovery, a generated private APP_KEY, fresh PostgreSQL 17 databases, all 14 migrations, the two sample seeders, platform checks, and actual network health/catalogue requests. It produced six products/seven promotions/zero customers, health 200 with `{data:{status:ok}}`, and SKU search returned only DEMO-KEYBOARD. The full fresh-project Pest suite also passed. All destructive test operations remained in that project's test database or the guarded original dedicated test database.

The supplied image/network is isolated from unrelated Docker projects; PostgreSQL has no host port. Composer and image creation still need normal package/image access, and native test portability is intentionally constrained by the guard. Existing-volume init scripts do not reprovision a missing test database. UID/GID and port customization are documented. README removes development-volume reset instructions from the primary setup flow and avoids the non-repeatable fixed demo user seeder.

`php artisan route:list --path=api --except-vendor -v --no-interaction` reports **17 application API routes** (GET also accepts HEAD):

| Method | Path | Name | Access / success |
|---|---|---|---|
| GET | /api/health | health | Public / 200 |
| POST | /api/auth/register | auth.register | Public, register limiter / 201 |
| POST | /api/auth/login | auth.login | Public, login limiters / 200 |
| GET | /api/auth/me | auth.me | Sanctum / 200 |
| POST | /api/auth/logout | auth.logout | Sanctum / 204 |
| GET | /api/products | products.index | Public / 200 paginated |
| GET | /api/products/{id} | products.show | Public / 200 |
| GET | /api/cart | cart.show | Sanctum / 200 |
| POST | /api/cart/items | cart.items.store | Sanctum / 201 |
| PATCH | /api/cart/items/{id} | cart.items.update | Sanctum / 200 |
| DELETE | /api/cart/items/{id} | cart.items.destroy | Sanctum / 204 |
| POST | /api/cart/promotion | cart.promotion.store | Sanctum / 200 |
| DELETE | /api/cart/promotion | cart.promotion.destroy | Sanctum / 204 |
| POST | /api/checkout | checkout | Sanctum / 201 new, 200 replay |
| GET | /api/orders | orders.index | Sanctum / 200 paginated |
| GET | /api/orders/{id} | orders.show | Sanctum / 200 |
| POST | /api/orders/{id}/cancel | orders.cancel | Sanctum / 200 first/repeat |

All API successes/errors preserve the existing envelopes, status mapping, ownership behavior, pagination, timestamp representation, and resource allow-lists. Catalogue/cart/order amounts use amount_minor/currency objects; order items use explicit raw minor-unit snapshot fields with the order's currency. No version prefix was introduced because the existing approved API is unversioned. Framework `/up`, web `/`, and vendor routes are outside this 17-route application API count.

### Executed verification

| Check | Actual result |
|---|---|
| Requested preflight `docker compose exec -T api php artisan test --compact` | **561 passed, 3000 assertions**, 17.43s |
| Search regressions against original implementation | **2 failed, 4 assertions** as expected; missing fields reproduced |
| Corrected catalogue controller/repository focus | **85 passed, 393 assertions**, 1.53s |
| Tightened standalone order file | **6 passed, 116 assertions**, 2.03s |
| Complete final-code PostgreSQL regression | **562 passed, 3007 assertions**, 16.19s |
| Four standalone concurrency files after correction/formatting | **18 passed, 343 assertions**, 6.08s; 14 actual overlap + 4 injected retry cases |
| Final packaging regression after documentation/collection creation | **562 passed, 3007 assertions**, 16.19s; then standalone concurrency **18 passed, 343 assertions**, 5.51s |
| Fresh isolated full Pest suite | **562 passed, 3008 assertions**, 17.58s; valid alternate contention branch explains +1 |
| Pint `vendor/bin/pint --dirty --format agent` | Exit 0; ordered new import; subsequent run `result: passed` |
| Composer `validate --strict` | Exit 0; composer.json valid |
| Composer `audit` | Exit 0; no vulnerability advisories found at audit time |
| Fresh Composer install / platform requirements | Exit 0; PHP 8.5.11 and required locked extensions satisfied; pdo_pgsql operational via migrations/tests |
| Fresh migration / seeds / network smoke | All 14 migrations; six products/seven promotions; health/catalogue HTTP success |
| Static analysis | Not installed or run; Larastan assessed above |
| Postman collection | Valid JSON; 18 requests cover all 17 API method/path pairs; payload templates parse; 19 scripts pass Node syntax checks; credentials/token defaults empty |
| Postman execution | **Not performed**; no Newman/Postman execution claim |
| Git whitespace / new-file whitespace | Passed; no files staged/committed/pushed |
| Working development / unrelated database safety | Original development remained zero users/products/orders/redemptions; dedicated database identities preserved; postgres-local running, restart count 0 before/after |

The audit artifacts are the durable record; temporary execution logs stayed outside the repository. Final packaging verification reran the full suite, standalone concurrency files, Pint, Composer validation/audit, JSON/payload/route/script validation, Git/new-file whitespace, and README/audit link checks after artifact creation. All passed. The temporary Compose containers/network/volume were then removed with their project label checked; the working Compose services remained up and postgres-local remained running with restart count 0. Later code changes require a new verification record rather than reuse of these results.

## 12. Submission checklist and Git boundaries

Ready:

- [x] Declared functional requirements traced to source, constraints, tests, and evidence level.
- [x] Recorded missing search behavior corrected; smallest safe regression added.
- [x] Authentication/ownership/input/resource/error boundaries reviewed; no confirmed critical vulnerability found.
- [x] Integer money/overflow/snapshots inspected and tested.
- [x] Real PostgreSQL contention and injected retry evidence separated.
- [x] Fresh source install, migrations, samples, HTTP entry point, and tests rehearsed in an isolated project.
- [x] Reviewer README, architecture/API/design documents, final audit, and secret-free Postman collection prepared.
- [x] Development/test databases retained; postgres-local/unrelated containers not stopped/restarted/changed.
- [x] Dependencies and prior migrations unchanged; no bonus capability, commit, push, or deployment.

Before submission:

- [ ] Reconcile the separate employer brief, including any performance/search/schema naming expectations, with this matrix; resolve R-01/R-02 if those stronger requirements are mandatory.
- [ ] Review the complete final diff and the known limitations; select repository URL/access and submission packaging.
- [ ] Import/run the Postman collection if manual collection execution is part of acceptance; set private local credentials, use a new email, and clear credentials/tokens before export.
- [ ] Rerun `docker compose exec -T api php artisan test --compact` before the final human-created commits/submission, especially if anything changes.
- [ ] Create the reviewed commits and submit by October 13, 2026; no automatic commit/push is authorized here.

Working tree at packaging: **11 modified tracked files**, plus **2 new files** (`docs/13-final-audit.md` and the Postman collection); all unstaged. No pre-existing user changes were present. Modified files are `.gitignore`, `.dockerignore`, README, the product repository, the two focused existing test files, and docs/01,02,06,07,08. No environment file/vendor/cache/log/generated test artifact is included.

Recommended commit boundaries (review and create manually):

1. `fix: complete product search requirements and tighten concurrency assertions` — product repository, ProductControllerTest, OrderConcurrencyTest, and search-contract portions of docs/02,06,07,08.
2. `docs: prepare independent audit and submission package` — README, docs/13, Postman collection, corrected setup/current-status documentation, `.gitignore` and `.dockerignore` hygiene. Split documentation hunks when a file participates in both commits.

No Git history was rewritten. No commit or push was created. The repository is ready for final review; unconditional employer acceptance remains subject to the original brief and the limitations above.

## 13. Interview discussion points

- Explain why a cart is an estimate rather than a reservation, and why current prices/stock must be reread under checkout locks.
- Walk through Cart → sorted Products → Promotion, then compare Order → sorted Products cancellation. Explain why plain relationship reads do not introduce reverse row-lock acquisition.
- Explain promotion lock + later Read Committed counts + ledger insert, especially first-use customers; uniqueness alone cannot enforce a global usage limit.
- Explain cart serialization plus customer/key uniqueness, permanent replay after cancellation/refill, and why failed transactions reserve no key.
- Demonstrate exactly-once restoration using an order lock and one transaction; equal timestamps are audit evidence, not a substitute for atomicity.
- Derive overflow-safe integer multiplication/addition and basis-point half-up rounding; distinguish PHP arithmetic from exact numeric operands used only in a database CHECK.
- Separate unit arithmetic, real PostgreSQL integration, fault injection, actual observed contention, and unmeasured production load. Explain what each can and cannot establish.
- Describe snapshots/no mutation API versus privileged SQL immutability; distinguish order component CHECKs from service-owned aggregate equality.
- Discuss the intentional Sanctum persistence exception and why a generic repository/new analyzer/refactor was not added without a concrete defect.
- Explain the requirement gap discovered independently, its failing regression, the grouped-filter fix, PHP lock prerequisite correction, and honest downgrade refusal.

## Bonus 7A follow-up

This report remains the historical mandatory-scope audit. Its baseline of 562 tests was independently re-executed before the separately requested Bonus 7A implementation. Administration, role and route-count exclusions above describe the earlier delivery. Current administrator authorization, eight new routes, additive migration, transaction decisions, Postman extension and verification evidence are recorded in [14-admin-management.md](14-admin-management.md). Existing audit fixes and packaging changes were retained; no automatic commits, pushes, deployment or unrelated-container mutations were made.
