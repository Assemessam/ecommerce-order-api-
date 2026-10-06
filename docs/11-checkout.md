# 11 — Milestone 5: Transactional Checkout & Order Creation

## Completion and preflight

Implemented authenticated checkout, real orders/items, inventory deduction, promotion redemption, immutable purchase snapshots, optional idempotency, and rollback/concurrency verification. This milestone stops here. Order browsing, cancellation, restoration, payments, shipping, refunds, administration, frontend, caching, and additional infrastructure are not implemented.

Preflight inspected Git status/history, README/design documents, Sanctum and CartPolicy, models, repository contracts/implementations, cart/pricing/promotion services and the pure calculator, migrations/constraints, and both existing independent-process concurrency suites/workers. The tree was clean at `5307457` (cart/promotions), preceded by `f84fefb` (catalogue) and `4d10dfc` (foundation/authentication). No `.ai/rules` directory exists. Laravel and testing skills were applied. Installed versions: PHP 8.5.11, Laravel 13.34.0, Sanctum 4.3.3, Pest 4.7.8, PHPUnit 12.5.33, Pint 1.32.1. Version-specific Boost documentation was consulted.

The complete Docker/PostgreSQL baseline executed: **407 passed, 1819 assertions, 7.99 seconds**. Test connections are guarded to Compose `postgres:5432/ecommerce_order_api_test`. The development ledger contained zero rows. Boost's database tools could not inspect the actual Compose connection (empty schema/missing PDO driver), so actual schema/records were inspected using Artisan and read-only psql in the project's container. No tinker or custom verification script was used.

### Resolved design contradictions

- The explicit milestone approves initial status **placed**. It supersedes the earlier pending/confirmed/payment-style lifecycle; no speculative payment or cancellation fields/statuses were added.
- Existing ledger tests/history can have no order. An additive nullable unique order FK preserves that history; every checkout writer requires a persisted real order. No fabricated orders/backfill are created.
- Old idempotency keys permanently replay their original successful order even if the cart is refilled. No purchase parameters are accepted, so no payload fingerprint or conflicting-body contract is needed. Ignored client fields cannot alter the purchase.
- The locked promotion serializes all global/customer ledger counts, including first use with no customer usage row. An additional customer aggregate/row lock would add no correctness here.

## Architecture and repository methods

The existing flow is preserved: Controller → Service → Repository Interface → Eloquent Repository → Model → PostgreSQL.

| Component | Responsibility |
|---|---|
| CheckoutController | Authenticated context, service call, OrderResource, 201 creation/200 replay |
| CheckoutRequest | Validate only the optional HTTP idempotency header |
| CheckoutService | Business workflow, owner policy, transaction, product checks, shared pricing/eligibility, coordinating writes |
| CheckoutResult | Readonly order plus replay flag |
| Order / OrderItem / OrderStatus | Typed persistence/relationships; initial placed state; historical snapshots |
| OrderResource / OrderItemResource | Explicit stored-field response allow-lists; no live catalogue lookup |
| EmptyCartException / CheckoutConflictException | Safe central 409 rendering; existing product/stock/promotion/overflow exceptions reused |
| OrderFactory / OrderItemFactory | Isolated PostgreSQL test data; no development purchase-history seeder |

Container-bound OrderRepositoryInterface exposes:

- `findByIdempotencyKey(User, string): ?Order`: customer-scoped lookup with items loaded.
- `create(User, CartView, ?string): Order`: persist order totals, currency, state, promotion inputs, key, timestamp.
- `createItems(Order, Collection<CartLine>): void`: persist purchase snapshots.
- `loadItems(Order): Order`: reload the persisted order and items for the response.

Existing contracts are extended:

- Cart: `loadCheckoutItems(Cart): Cart`, `clear(Cart): void`; existing `lockForUser` and `setPromotion` are reused.
- Product: `lockByIds(array<int,int>): Collection<int,Product>`, `deductStock(Product, int): bool`.
- Promotion: `findByIdForUpdate(int): ?Promotion`, `createRedemption(Order, string $redemptionKey): PromotionRedemption`; existing counts/eligibility are reused.

Repositories participate in the service transaction and do not independently commit it. Database access stays in repositories. Setting the locked product relation on an already-loaded cart item is in-memory preparation, not another database query.

## Database migrations and constraints

Three additive migrations were applied to development with `php artisan migrate --no-interaction`, without a reset:

1. `2026_10_06_151221_create_orders_table.php`: bigint identity, restricted customer/promotion FKs, placed status, currency, integer totals, historical promotion code/type/value/cap, optional key, placed_at, timestamps. Unique `(user_id, idempotency_key)`, owner/history and promotion indexes. CHECKs enforce placed, uppercase three-letter currency, nonnegative consistent totals, valid key format, and coherent promotion snapshots.
2. `2026_10_06_151222_create_order_items_table.php`: bigint identity and quantities, restricted real order/product FKs, name/SKU/unit-price/line-subtotal snapshots, timestamps. Unique `(order_id, product_id)` and product index. CHECKs enforce positive quantity and nonnegative exact quantity × unit price. The CHECK uses exact PostgreSQL numeric operands to avoid overflow during constraint evaluation; business arithmetic uses checked PHP integers.
3. `2026_10_06_151223_add_order_id_to_promotion_redemptions_table.php`: nullable restricted order FK plus unique order_id. Existing UUID uniqueness, identities, discounts, references, and count indexes remain intact.

Arbitrary NULL legacy links remain database-valid for compatibility; the production checkout writer always supplies a real order. Non-NULL orphan links, duplicate order redemptions, duplicate customer keys, and deletion of referenced history are rejected. Legacy rows continue to count against limits. Upgrade testing creates a legacy row before applying the additive migration and verifies unchanged UUID/discount/accounting and no placeholder orders. See [the complete schema](06-database-design.md).

Rollback drops linkage/item/order tables and loses purchase history, so retained deployments should use forward fixes. No previous migration was edited. Post-migration development counts were zero users, tokens, products, carts, cart items, orders, order items, and redemptions. No sample seeding ran.

## Transaction, inventory, promotion, and money

CheckoutService owns `DB::transaction(..., attempts: 3)`. One invocation generates a redemption UUID once, reused across transaction attempts. The workflow:

1. Lock the authenticated customer's cart and authorize its update ability.
2. Look up a supplied customer/key association; return the original persisted order on replay.
3. Load items and reject absent/empty carts.
4. SELECT products FOR UPDATE with ORDER BY id ASC; require every referenced product, active state, and sufficient current stock.
5. Attach those locked products to items and call CartPricingService for checked line/subtotal arithmetic.
6. Lock the persisted selected promotion and reuse PromotionService eligibility plus PromotionCalculator. Counts are read after the promotion lock.
7. Persist the order and item snapshots, deduct each quantity conditionally, insert one order-linked redemption if selected, clear items and selection, and reload the order.
8. Commit before the controller serializes the result. Any exception rolls back all writes.

Approved lock order: **Cart → Products ascending ID → Promotion → customer/global ledger reads and insertion**. Product/cart mutations retain the existing cart-first convention. No SKIP LOCKED, cart reservation, external API calls, emails, or irreversible effects are introduced.

Inventory deduction uses atomic `stock_quantity = stock_quantity - quantity WHERE stock_quantity >= quantity`; failure raises a stock conflict and rolls back even preceding deductions. Product locks prevent stale overwrites, and the existing stock >= 0 CHECK remains the final defense. Deterministic product order reduces deadlock cycles.

PostgreSQL **Read Committed** is required: a waiter locks the committed product version; the subsequent promotion count statement sees the previous redeemer's commit. Every production redemption path must follow this lock/count/write protocol. The promotion row lock serializes both global and per-customer consumption, including missing first-use ledger rows. Selection alone never reserves usage. Without a selected promotion no redemption is created; an eligible selected promotion with a zero clamped discount still consumes one use.

Integer subtotal arithmetic and the existing quotient/remainder basis-point calculator are shared, not duplicated. Percentage discounts round half-up before cap/subtotal clamping. Fixed discounts are capped/clamped similarly. `total_minor = subtotal_minor - discount_minor`; item subtotal = quantity × locked unit price. Multiplication and accumulation are checked before PHP can convert overflow to float. Signed-bigint overflow rejects the checkout with CART_TOTAL_TOO_LARGE. No tax/shipping/currency conversion is included.

Detected concurrency errors retry the entire transaction up to three attempts. Constraints/SQLSTATE 40001, 40P01, or 55P03 escaping the rolled-back transaction become safe CHECKOUT_CONFLICT; unexpected failures retain generic 500 reporting. The retry tests inject PostgreSQL 40P01 errors after redemption insertion with the normal deadlock message. Two failed attempts then one success leave exactly one purchase; three failures leave no purchase. These are server-error injection tests, not claims that an actual deadlock cycle was observed.

### Interview explanation: preventing overselling

Inside one transaction I lock the customer's cart, then every purchased product row in ascending ID order using PostgreSQL FOR UPDATE. I validate stock and read prices after obtaining those locks. A competing buyer waits and then sees the committed remaining stock. I also use a conditional decrement requiring sufficient stock and retain the nonnegative database constraint. Order snapshots, deduction, promotion usage, and cart clearing commit together; any failure rolls everything back. Real separate-process tests prove that stock five cannot fulfill simultaneous purchases of four and three.

## API, snapshots, and idempotency

`POST /api/checkout` requires Sanctum. No body/query purchase parameters are supported; forged identity, cart, prices, names, SKUs, quantities, coupons, totals, and body idempotency keys are ignored. The selected code is the persisted cart selection.

The optional header is 1..128 case-sensitive ASCII characters, starting with a letter/digit and continuing with letters/digits/period/underscore/colon/hyphen. Empty/whitespace/malformed/oversized input returns 422 with structured field errors. The database validates its format and uniqueness per customer. No key is reserved by failed attempts. Success returns 201; replay returns 200 before empty-cart or current stock/promotion eligibility checks. Refilled carts remain untouched on old-key replay; new purchases require new keys. Keys have no expiry. A different customer may use the same key. Different/no keys cannot repurchase a cleared cart.

Order items store historical product name/SKU/unit price/quantity/line subtotal. Orders store purchase currency, totals, and promotion code/type/value/cap. Product/promotion edits and configuration changes do not affect old responses. No snapshot edit endpoint exists. The first response reloads stored timestamps, avoiding an in-memory/database precision mismatch discovered by replay tests. See [request and full response examples](07-api-contracts.md).

An abbreviated successful resource has `status: placed`, `items`, `subtotal: {amount_minor:2010,currency:USD}`, `discount: {amount_minor:201,currency:USD}`, `total: {amount_minor:1809,currency:USD}`, promotion calculation inputs, and placed/created/updated timestamps. Item amounts inherit the order currency. Keys and live relations are not exposed.

Errors retain `error.code`, safe message, optional details, matching request_id/X-Request-ID, and no-store/private headers. Authentication is 401; empty/stock/inactive/overflow/usage conflicts are 409; invalid coupon dates/status/minimum and malformed headers are 422; missing product/unresolved selection is 404; unexpected database/runtime failure is safe 500. SQL/stack details never appear even with debug enabled. Failed checkout preserves the cart and selected promotion.

## Automated and independent-process evidence

Tests exercise success with one/multiple products, quantities/snapshots/totals/status/ownership, forged inputs, changed prices/names/SKUs/coupon/currency, half-up/fixed/capped/clamped/zero/max-bigint discounts, dates/minimum/global/customer limits, legacy usage, stock changes, overflow, keys/replay/refilled carts/customer isolation, database integrity and migration upgrade, and ordered service locks. Missing locked products/promotions are tested through repository fault injection because existing FKs prevent ordinary orphaned cart fixtures.

Failure injection occurs after order creation, item insertion, inventory deduction, redemption insertion, and cart item deletion. Each test verifies zero partial orders/items/redemptions, restored stock, preserved quantities/selection, safe JSON, and a subsequent successful retry with the same key. A later conditional inventory failure rolls back an earlier deduction. Real database constraint failure verifies safe conflict mapping.

The contention file uses DatabaseMigrations so fixtures are committed. It reuses the existing Symfony Process HTTP-kernel worker, extended to accept the optional header and emit lock SQL. Each worker has its own PHP process/PostgreSQL connection, an explicit guarded test environment, application_name, and bounded timeout. An independent observer queries pg_stat_activity; **both distinct PIDs must be active lock waiters before the parent releases the held product/promotion/cart barrier**. A five-second deadline fails missing overlap; cleanup always releases locks/stops workers. Tokens pass through stdin. These are actual authenticated HTTP kernel requests, not network transport.

Observed final standalone contention run:

| Scenario | Worker PostgreSQL PIDs | HTTP results | Committed result |
|---|---|---|---|
| A: stock 5, quantities 4 and 3 | 19266, 19265 | 201 / 409 INSUFFICIENT_STOCK | One order/item, stock 1, losing cart intact, no partial losing order |
| B: last coupon, distinct products | 19270, 19271 | 201 / 409 GLOBAL_USAGE_LIMIT | One order/item/redemption, stocks 3 and 5, losing cart/selection intact |
| C: same customer/key | 19275, 19276 | 201 / 200 | Both responses identical/order 1; stock 3; one order/item/redemption |
| D: overlapping products, reverse insertion | 19280, 19281 | 201 / 201 | Two orders/four items; stock 5 on each product; no cart items |

Winning scheduling is deliberately nondeterministic; assertions accept either valid winner. Tests also assert Read Committed, distinct connections, exact database, matching barrier queries, ascending product SQL, and correct final state. Two additional PostgreSQL fault-injection tests verify retries/exhaustion separately.

## Verification — October 6, 2026

All suite invocations in final verification ran sequentially in the existing Docker setup against guarded PostgreSQL. No SQLite concurrency claim is made.

| Check | Exact final result |
|---|---|
| Preflight full baseline | 407 passed, 1819 assertions; 7.99s |
| Final focused controller/service/order tests | 82 passed, 606 assertions; 2.92s |
| Final standalone contention/retry suite | 6 passed, 117 assertions; 2.43s |
| Full PostgreSQL regression | 495 passed, 2544 assertions; 13.34s |
| Pint `--dirty --format agent` | Exit 0; corrected imports/spacing/PHPDoc; final run reported passed |
| Composer `validate --strict` | Exit 0; composer.json is valid |
| Composer `audit` | Exit 0; no security vulnerability advisories |
| Git whitespace validation | Passed for all tracked changes and all 23 untracked files |
| Additive development migrations | All three applied successfully; actual columns/indexes/FKs inspected with Artisan |
| API route inspection | 14 routes, including authenticated POST checkout; no cancellation/order browsing routes |
| Development data | Zero users/tokens/products/carts/items/orders/redemptions added |
| Unrelated postgres-local | Never modified; observed running with zero restarts |
| Static analysis/load benchmark | Not run; none installed/configured; no performance guarantee claimed |

During development, one pair of suite invocations accidentally overlapped against the single test database and caused missing-table failures; they were rerun sequentially. Constraint test fixtures were adjusted to isolate the intended quantity CHECK rather than violate multiple CHECKs. The injected deadlock message was corrected to match Laravel's actual concurrency detector. Replay tests found and fixed a real timestamp precision mismatch. Final results supersede those intermediate failures.

## Git, files, trade-offs, and next milestone

The working tree contains 26 modified tracked files and 23 new untracked files and remains unstaged/uncommitted; no commit or push was made. Existing architecture, dependencies, environment/Docker configuration, earlier migrations, and unrelated work were preserved. The file inventory below is the milestone diff against the clean preflight tree.

Created (23 files):

- `app/Contracts/Repositories/OrderRepositoryInterface.php`
- `app/DTOs/Checkout/CheckoutResult.php`
- `app/Enums/OrderStatus.php`
- `app/Exceptions/Domain/CheckoutConflictException.php`
- `app/Exceptions/Domain/EmptyCartException.php`
- `app/Http/Controllers/Api/CheckoutController.php`
- `app/Http/Requests/Checkout/CheckoutRequest.php`
- `app/Http/Resources/OrderItemResource.php`
- `app/Http/Resources/OrderResource.php`
- `app/Models/Order.php`
- `app/Models/OrderItem.php`
- `app/Repositories/Eloquent/EloquentOrderRepository.php`
- `app/Services/Checkout/CheckoutService.php`
- `database/factories/OrderFactory.php`
- `database/factories/OrderItemFactory.php`
- `database/migrations/2026_10_06_151221_create_orders_table.php`
- `database/migrations/2026_10_06_151222_create_order_items_table.php`
- `database/migrations/2026_10_06_151223_add_order_id_to_promotion_redemptions_table.php`
- `docs/11-checkout.md`
- `tests/Feature/Http/Controllers/Api/CheckoutControllerTest.php`
- `tests/Feature/Models/OrderTest.php`
- `tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php`
- `tests/Feature/Services/Checkout/CheckoutServiceTest.php`

Modified (26 files):

- `README.md`
- `app/Contracts/Repositories/CartRepositoryInterface.php`
- `app/Contracts/Repositories/ProductRepositoryInterface.php`
- `app/Contracts/Repositories/PromotionRepositoryInterface.php`
- `app/Models/Product.php`
- `app/Models/Promotion.php`
- `app/Models/PromotionRedemption.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Repositories/Eloquent/EloquentCartRepository.php`
- `app/Repositories/Eloquent/EloquentProductRepository.php`
- `app/Repositories/Eloquent/EloquentPromotionRepository.php`
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
- `tests/Feature/Models/PromotionRedemptionTest.php`
- `tests/Fixtures/cart-promotion-request.php`

Known trade-offs: popular products and promotions serialize purchasers; indexed ledger counting grows with history; all supported writers must honor the same lock protocol. Legacy NULL order links remain allowed for raw imports until a genuine migration/backfill policy exists. Snapshot immutability is purchase-time storage plus no edit endpoint, not a database prohibition on privileged SQL edits. Order monetary equality and item equality are database-checked; aggregate subtotal equals item sum is owned by the service. Keys persist for the order's lifetime. Missing cart checks do not create a cart. There is one configured deployment currency, no inventory/coupon reservation, and no arbitrarily large integer support beyond signed bigint. Privacy/history deletion rules require later approval.

Recommended next milestone: **Milestone 6 — owner-scoped order list/detail and cancellation**, after agreeing on cancellable states for placed orders and retention/promotion-usage policy. Cancellation must add exactly-once restoration and its own PostgreSQL contention tests. No part of that milestone is implemented here.
