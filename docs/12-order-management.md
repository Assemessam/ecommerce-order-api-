# 12 — Milestone 6: Order Management & Cancellation

## Outcome and preflight

Implemented authenticated order history, historical details, and concurrency-safe cancellation. Only `placed → cancelled` is supported. Repeated cancellation returns the existing order with no additional stock or timestamp writes. Promotion usage remains counted, and original checkout keys replay the cancelled order. Stop after this milestone; no payments, refunds, shipping, administration, frontend, Redis, caching, email, or extra lifecycle states were added.

Preflight found a clean tree at `f4d7921` (transactional checkout), preceded by `5307457` (cart/promotions), `f84fefb` (catalogue), and `4d10dfc` (foundation/authentication). Reviewed README, checkout report, design/API/business/test documents, actual PostgreSQL order/item/product/redemption columns, constraints, indexes and FKs, CheckoutService and repository dependencies, policies/resources/errors, and existing process/barrier tests. No `.ai/rules` exists. Applied Laravel/testing skills and consulted installed-version Boost documentation. Installed direct dependencies include Laravel 13.34.0, Sanctum 4.3.3, Pest 4.7.8, PHPUnit 12.5.33, Pint 1.32.1; Docker runs PHP 8.5.

Executed the complete baseline in the existing Compose API container against guarded `postgres:5432/ecommerce_order_api_test`: **495 passed, 2544 assertions, 17.00s**. Boost's host connection returned an empty schema; actual Compose schema was inspected read-only with project-container psql. No tinker or verification scripts were created. The unrelated `postgres-local` container was never modified or stopped. No pre-existing uncommitted work existed.

There is no architectural conflict. The prior placed-only CHECK requires an additive widening to allow the explicitly approved cancellation state. The ledger/FKs are compatible with retained usage; no change to checkout accounting or workflow is necessary.

## Architecture and responsibilities

The existing flow remains Controller → Service → Repository Interface → Eloquent Repository → Models → PostgreSQL.

| Component | Responsibility |
|---|---|
| OrderController | Authenticated identity, validated page input, service invocation and resources |
| OrderQueryRequest | Query-only page/per-page validation; unknown inputs cannot change ownership or sorting |
| OrderService | Owner policy, state/overflow rules, one cancellation transaction, coordinating restoration and status |
| OrderRepositoryInterface / EloquentOrderRepository | Owner-scoped pagination/find/lock, eager item queries and cancellation persistence |
| ProductRepositoryInterface / EloquentProductRepository | Existing ascending-ID row locking plus focused conditional inventory increment |
| OrderPolicy | Owner view/cancel abilities; deny another customer as 404 |
| OrderResource / OrderItemResource | Existing persisted snapshot allow-lists, conditional items, plus nullable cancellation audit fields |
| Order / OrderStatus | Typed cancellation timestamp casts and placed/cancelled enum |
| Domain exceptions | Safe INVALID_ORDER_STATUS, INVENTORY_RESTORATION_OVERFLOW, ORDER_CANCELLATION_CONFLICT |

Added order repository methods: `paginateForUser(User, page, perPage)`, `findForUser(User, id)`, `lockForUser(User, id)`, `markCancelled(Order, CarbonInterface)`. Existing create/items/replay methods and container binding are reused. The product contract adds `restoreStock(Product, quantity): bool`. Repositories never own the workflow transaction or choose cancellation eligibility. A CancellationResult DTO would add no information: both first and repeated cancellations have the same 200/resource contract. No duplicate model/resource/repository was created.

## Schema and deployment

One additive migration: `2026_10_06_153712_add_cancellation_metadata_to_orders_table.php`.

- Nullable `cancelled_at` and `inventory_restored_at`, both `timestamptz(6)`.
- `orders_status_valid` permits only placed/cancelled; default stays placed.
- `orders_cancellation_state_valid`: placed requires both markers NULL; cancelled requires both non-NULL and equal.
- `(user_id, created_at, id)` indexes implemented history queries; existing indexes/keys/FKs/money constraints are preserved.

The separate status constraint rejects unsupported lifecycle values; marker constraint implications preserve the prior status error boundary. Existing placed rows remain valid without a backfill. No tables are recreated, order records are fabricated, or history is rewritten. Migration upgrade testing preserves purchase attributes, item snapshots, idempotency key and ledger, then cancels that existing purchase successfully.

Applied the migration additively to the development Compose database using `php artisan migrate --no-interaction`; confirmed actual columns/indexes/CHECKs/FKs. Development remained empty (zero users, products, orders, redemptions); no seeding ran.

Downgrade intentionally refuses retained cancelled rows before removing audit fields because the old schema cannot represent restored inventory. Use forward fixes for retained deployments. Test coverage proves refusal leaves cancellation history intact. The independent-process tests remove their committed order fixtures/dependents from the guarded test database before DatabaseMigrations rollback. An initial contention run passed its request/inventory assertions but failed migration teardown; explicit fixture cleanup resolved that test-lifecycle issue.

## API contract and examples

| Method | Endpoint | Success |
|---|---|---|
| GET | `/api/orders` | 200 owner-scoped paginated summaries |
| GET | `/api/orders/{id}` | 200 owner-scoped historical order with items |
| POST | `/api/orders/{id}/cancel` | 200 cancelled order, including on retry |

All require a Sanctum bearer token. Missing/invalid token is 401. Details and cancellation treat missing/foreign/malformed/overflowing IDs identically as 404 RESOURCE_NOT_FOUND; queries are owner-scoped before policy evaluation. No customer profile or token/key/ledger/live relation is exposed. The existing public user_id identifies only the authenticated owner's returned order.

History defaults to page 1 and 15 results, with page 1..2147483647 / per_page 1..100. Values outside that range, non-integers, or explicit empty values return 422 VALIDATION_FAILED with field errors. Sort is fixed to `created_at DESC, id DESC`; arbitrary user_id/sort/direction inputs are ignored and omitted from links. GET bodies do not override query pagination. Items are omitted from list summaries. List and detail each use two repository queries independent of item count (pagination count + orders; detail order + items). No live product/promotion queries or read transaction are needed.

```http
GET /api/orders?page=1&per_page=15
Accept: application/json
Authorization: Bearer <token>
```

Example list response (native pagination navigation entries are abbreviated):

```json
{
  "data": [{
    "id": 1, "user_id": 7, "status": "placed", "currency": "USD",
    "subtotal": {"amount_minor": 2010, "currency": "USD"},
    "discount": {"amount_minor": 201, "currency": "USD"},
    "total": {"amount_minor": 1809, "currency": "USD"},
    "promotion": {"id": 3, "code": "SAVE10", "type": "percentage", "value": 1000, "maximum_discount": null},
    "cancelled_at": null, "inventory_restored_at": null,
    "placed_at": "2026-10-06T12:00:00.000000Z",
    "created_at": "2026-10-06T12:00:00.000000Z", "updated_at": "2026-10-06T12:00:00.000000Z"
  }],
  "links": {"first": "http://localhost:8091/api/orders?page=1&per_page=15", "last": "http://localhost:8091/api/orders?page=1&per_page=15", "prev": null, "next": null},
  "meta": {"current_page": 1, "from": 1, "last_page": 1, "links": ["..."], "path": "http://localhost:8091/api/orders", "per_page": 15, "to": 1, "total": 1}
}
```

`GET /api/orders/1` uses the same full resource as checkout, adding `items` to the summary above:

```json
"items": [{"id":1,"product_id":42,"product_name":"Travel Mug","product_sku":"MUG","quantity":2,"unit_price_minor":1005,"line_subtotal_minor":2010}]
```

Snapshot names/SKUs/prices/totals and promotion inputs remain correct after current product/promotion edits or deployment currency changes. No order-item mutation occurs during cancellation.

```http
POST /api/orders/1/cancel
Accept: application/json
Authorization: Bearer <token>
```

No body is required. Extra values cannot supply restoration quantities, ownership, snapshots, or timestamps. Example 200 first/repeated cancellation response:

```json
{
  "data": {
    "id": 1, "user_id": 7, "status": "cancelled", "currency": "USD",
    "items": [{"id":1,"product_id":42,"product_name":"Travel Mug","product_sku":"MUG","quantity":2,"unit_price_minor":1005,"line_subtotal_minor":2010}],
    "subtotal": {"amount_minor":2010,"currency":"USD"},
    "discount": {"amount_minor":201,"currency":"USD"},
    "total": {"amount_minor":1809,"currency":"USD"},
    "promotion": {"id":3,"code":"SAVE10","type":"percentage","value":1000,"maximum_discount":null},
    "cancelled_at": "2026-10-06T12:30:00.123456Z",
    "inventory_restored_at": "2026-10-06T12:30:00.123456Z",
    "placed_at": "2026-10-06T12:00:00.000000Z",
    "created_at": "2026-10-06T12:00:00.000000Z", "updated_at": "2026-10-06T12:30:00.000000Z"
  }
}
```

`updated_at` has the existing order table's whole-second precision; the two new audit columns preserve microseconds. Every response reloads persisted values or reads the already-persisted cancelled row, so repeats preserve the exact original timestamp strings.

## Transaction, state and inventory

OrderService wraps cancellation in `DB::transaction(..., attempts: 3)`:

1. Validate the numeric signed-bigint ID; SELECT the owner-scoped order FOR UPDATE and eagerly read its snapshots.
2. Authorize cancel via OrderPolicy.
3. For cancelled with coherent markers, return that existing order without inventory queries/writes. Inconsistent cancellation metadata is a safe conflict.
4. Require placed with both markers null and nonempty snapshots.
5. Lock all referenced product rows with ORDER BY id ASC FOR UPDATE.
6. Iterate snapshot quantities in ascending product ID order. Reject a missing product as an integrity conflict. Check `stock <= PHP_INT_MAX - quantity` before arithmetic; conditionally increment stock with the same bound.
7. Save cancelled status and equal cancellation/restoration timestamps in a single order update, then reload stored order/items.
8. Commit before returning the resource. Any exception rolls back all preceding writes.

The existing unique order/product snapshot pair means each purchased product is restored once per order. Stock increases use SQL atomic arithmetic, never a stale model stock assignment. Quantities are positive signed bigint and product stock is nonnegative by existing PostgreSQL constraints. Exact maximum restoration, including purchasing/restoring PHP_INT_MAX units of a zero-price item, is verified. An overflow on a later product rolls back earlier increments too. Inactive products are restored normally. Product prices, snapshots, cart items/selection and ledger remain unchanged.

The invariant for newly cancelled orders links status and equal non-null audit markers to restoration by the transaction. Database CHECKs enforce metadata coherence; they cannot verify an arbitrary privileged SQL writer actually updated inventory. Supported writers must use the service and lock protocol. The marker adds auditability/defense and does not replace row locking.

## Lock-order compatibility and exactly-once explanation

Checkout keeps **Cart → Products ascending ID → Promotion**. Cancellation takes **Order → Products ascending ID**. Cancellation never acquires Cart or Promotion, changes FK identities, or writes ledger rows. Checkout creates a new order after product locking; it does not lock a pre-existing cancelled order when replaying a key. The shared product set is acquired in the same order, so no lock-order inversion is introduced. No external effect occurs inside the transaction.

PostgreSQL Read Committed lets a waiting cancellation see the committed cancelled row and a waiting buyer see the committed product stock. If a buyer wins before restoration and lacks stock, 409 is valid; if cancellation wins, that buyer can succeed. No lost updates or negative stock are allowed. Bounded retries apply to detected concurrency errors; exhausted constraints/40001/40P01/55P03 map to a safe 409 after rollback.

### Interview explanation

**How do you ensure inventory is restored exactly once if a customer sends multiple concurrent cancellation requests?**

I lock the owned order row inside one PostgreSQL transaction before checking its status. Concurrent requests for that order wait. The first request locks the purchased products in ascending ID order, restores snapshot quantities, and writes cancelled status and restoration/cancellation timestamps in that same transaction. After commit, the waiting request sees cancelled and returns the existing order without touching stock or timestamps. If the first request fails, its stock updates and markers roll back together, leaving the next request free to perform one complete cancellation. The marker is audit evidence; the row lock and atomic transaction provide exactly-once restoration. Independent-process tests observe two lock waiters and prove one transition/increment with two successful responses.

## Promotion and checkout compatibility

Cancelling never deletes a redemption, decrements a counter, clears discount snapshots, or reattaches a code. The ledger is the usage source of truth; the original successful purchase remains counted against global/customer limits. This is an explicit business decision, compatible with the existing order FK and ledger counts.

An original successful customer-scoped checkout key permanently identifies that order, including its cancelled state. CheckoutService is unchanged: replay lookup returns before empty-cart/product/promotion validation and performs no second deduction or redemption. Tests cancel a promoted order, refill the cart, replay the old key, verify the identical cancelled response and untouched new cart line, and prove the exhausted promotion remains unavailable.

## Failures and rollback evidence

| Failure | HTTP/result |
|---|---|
| No token | 401 UNAUTHENTICATED |
| Missing/foreign/invalid ID | Indistinguishable 404 RESOURCE_NOT_FOUND |
| Bad list pagination | 422 VALIDATION_FAILED with field errors |
| Ineligible/inconsistent placed state | 409 INVALID_ORDER_STATUS |
| Incomplete snapshots, missing product, inconsistent cancelled markers | 409 ORDER_CANCELLATION_CONFLICT |
| Checked/conditional stock overflow | 409 INVENTORY_RESTORATION_OVERFLOW |
| Integrity/exhausted recognized concurrency failure | 409 ORDER_CANCELLATION_CONFLICT |
| Unexpected database/runtime failure | Generic 500 INTERNAL_ERROR; original exception reported server-side |

Existing JSON error envelope, request IDs, and no-store/private response headers are preserved. SQL, traces and injected private exception text are absent even with debug enabled. Tests inject failure during the second restore, before order status saving, after order status/markers saving, and a real PostgreSQL division-by-zero error. All assert original inventory, placed state, null markers, unchanged snapshots and no recreated cart items. A real money CHECK violation maps to safe 409 and also rolls back stock. Separate conditional-update refusal and later-product overflow prove no surviving partial restoration.

Only placed/cancelled states are valid in this schema. Defensive ineligible/corrupt-state service branches use repository fault injection because PostgreSQL deliberately prevents those fixtures. No extra lifecycle enum is added just to test a future state. Independent PostgreSQL 40P01 error injections prove three whole-transaction attempts and atomic exhaustion; these are not claims that an actual deadlock cycle was observed.

## Verification and independent-process results

All suites ran sequentially in the existing Docker environment against guarded PostgreSQL; no SQLite claim is made. Final verification on October 6, 2026:

| Check | Exact executed result |
|---|---|
| Full preflight baseline | 495 passed, 2544 assertions, 17.00s |
| Final focused new order/controller/service/schema/policy tests | 60 passed, 340 assertions, 1.97s |
| Standalone new cancellation concurrency/retry file | 6 passed, 116 assertions, 1.86s |
| Final all four standalone concurrency files | 18 passed, 343 assertions, 5.53s |
| Complete PostgreSQL regression | 561 passed, 3000 assertions, 15.28s |
| Pint required `--dirty --format agent` and explicit new PHP files | Exit 0; imports/spacing corrected; final runs passed |
| Composer `validate --strict` | Exit 0; composer.json is valid |
| Composer `audit` | Exit 0; no security vulnerability advisories |
| Git whitespace check (tracked and every new file) | Passed |
| Additive development migration / actual schema inspection | Applied successfully; columns/indexes/CHECKs/FKs confirmed |
| API route inspection | 17 routes; three added authenticated order endpoints |
| Development data | Zero users, products, orders and redemptions; no seeding |
| Unrelated postgres-local | Never changed/stopped; observed running with zero restarts |
| Static analysis / load benchmark | Not run; no static analyzer is configured |

The four final independent-process cancellation scenarios below are from the standalone all-concurrency run. Each row required both distinct PostgreSQL workers to be active lock waiters before the parent released the barrier. Application-name observation uses an independent observer connection and bounded deadlines; sleeps merely poll observed state, not assume overlap. Workers use actual authenticated HTTP-kernel requests, not network transport.

| Scenario | Worker PostgreSQL PIDs | HTTP results | Final stock / restoration evidence |
|---|---|---|---|
| A: same customer/order, two cancellations | 22165 / 22164 | 200 / 200 | Stock 10; one order UPDATE and one inventory increment; identical responses/markers |
| B: cancellation versus another buyer, stock available | 22170 / 22169 | 200 / 201 | Stock 6 = 10 − 4; one restoration; two valid orders |
| B boundary: buyer needs restored stock | 22174 / 22175 | 200 / 201 | Stock 2 = 5 − 3; one restoration; both committed |
| C: reverse-insertion overlapping products | 22180 / 22181 | 200 / 201 | Stocks 5 and 6; two ascending product restores; one order UPDATE |

The boundary test permits an INSUFFICIENT_STOCK 409 if checkout obtains the product before cancellation; its corresponding final stock must be 5 with no losing purchase. The observed final run selected the cancellation-first valid outcome. The full regression independently reran all scenarios successfully (PIDs 22686/22685, 22691/22690, 22695/22696, 22700/22701). Two additional injected-deadlock tests verify three attempts, one eventual restoration or complete rollback, and no duplicate orders/items.

The final suite adds 66 tests / 456 assertions to the executed baseline. Existing authentication, catalogue, cart, promotions and checkout tests pass. Transaction failure and schema upgrade/downgrade tests pass; no prior test was deleted. The initial teardown failure described above was resolved and all final results supersede it.

## File inventory and Git

The working tree has **21 modified tracked files and 14 new untracked files**, all unstaged and uncommitted.

Created (14):

- `app/Exceptions/Domain/InvalidOrderStatusException.php`
- `app/Exceptions/Domain/InventoryRestorationOverflowException.php`
- `app/Exceptions/Domain/OrderCancellationConflictException.php`
- `app/Http/Controllers/Api/OrderController.php`
- `app/Http/Requests/Order/OrderQueryRequest.php`
- `app/Policies/OrderPolicy.php`
- `app/Services/Order/OrderService.php`
- `database/migrations/2026_10_06_153712_add_cancellation_metadata_to_orders_table.php`
- `docs/12-order-management.md`
- `tests/Feature/Http/Controllers/Api/OrderControllerTest.php`
- `tests/Feature/Models/OrderCancellationTest.php`
- `tests/Feature/Policies/OrderPolicyTest.php`
- `tests/Feature/Services/Order/OrderConcurrencyTest.php`
- `tests/Feature/Services/Order/OrderServiceTest.php`

Modified (21):

- `README.md`
- `app/Contracts/Repositories/OrderRepositoryInterface.php`
- `app/Contracts/Repositories/ProductRepositoryInterface.php`
- `app/Enums/OrderStatus.php`
- `app/Http/Resources/OrderResource.php`
- `app/Models/Order.php`
- `app/Repositories/Eloquent/EloquentOrderRepository.php`
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
- `tests/Fixtures/cart-promotion-request.php`
 No dependencies or environment/Docker configuration were changed. No files were staged, committed, or pushed. Prior checkout implementation and test files were preserved; the shared test worker only gained successful-write telemetry.

## Known limits and next milestone

Popular products serialize short transactions. No production-scale load benchmark or static analyzer was run; none is installed/configured. Full item detail is eager-loaded and may grow with order size; list summaries remain bounded. Offset pagination is deterministic for fixed data but concurrent new orders may shift page boundaries. The existing placed-date index is retained alongside the creation-date history index. Money/stock are bounded by signed bigint and one deployment currency. Privileged SQL edits can bypass service-level snapshot/restoration semantics while satisfying metadata CHECKs, so all supported writers must use the protocol. Downgrade cannot retain cancelled history in the placed-only schema. Tokens/retention/account deletion require later policy work.

Recommended next milestone: **Milestone 7 — Hardening and submission**, including fresh-clone deployment rehearsal, measured query/performance review, retention/privacy decisions and final submission documentation. Stop here; no next-milestone or bonus implementation is included.
