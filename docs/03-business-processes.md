# 03 — Business Processes

## Browse catalogue

1. Client sends search/filter/sort/page parameters.
2. Form Request validates types, ranges, and allow-listed sort values.
3. Product service passes a query DTO and the active visibility policy to the repository.
4. Repository applies the requested status plus validated filters, sorting, and pagination, and returns a paginator.
5. Product resources return stable public fields and pagination metadata.

Milestone 2 implements this process. Detail lookup goes through the service and repository; the service treats inactive/missing products as not found. Active zero-stock products remain visible unless availability is filtered. These operations are read-only and have no transaction boundary.

## Maintain cart (implemented in Milestone 3)

1. Sanctum authenticates the customer; Form Requests validate positive integer product/quantity input.
2. GET looks up the owner cart with items/products eagerly loaded and calculates an estimate. Without a stored cart it returns an empty resource without an insert.
3. POST/PATCH/DELETE enter a CartService-owned transaction and acquire the owner's cart lock. Only POST may safely create the first cart, protected by unique customer ownership and PostgreSQL conflict-aware insertion.
4. POST/PATCH lock the affected product after the cart and enforce eligibility/current stock in the service. POST adds to any existing line quantity; PATCH replaces the quantity using an owner-scoped cart item ID.
5. The repository persists changes; the service calculates current-price line subtotals/cart subtotal and availability. POST/PATCH include this calculation in the transaction, so calculation failures roll back writes too.
6. DELETE removes only an owned line, returns 204, and leaves an empty cart after the final removal. It requires neither product eligibility nor subtotal calculation.

Cart checks provide early feedback and never reserve/deduct stock. Separate customers can each cart a quantity within stock even when their combined quantities exceed it. Later inactive/out-of-stock/insufficient-stock lines remain visible with current-price subtotals and availability reasons. Future mutations revalidate the affected product; checkout must independently revalidate stock under the documented locks. Cross-customer line IDs return the same 404 as missing IDs. All mutations acquire Cart before Products; future checkout retains the full lock order below.

## Apply a promotion (implemented in Milestone 4)

1. Sanctum authenticates the customer; Form Request normalizes/validates `code`.
2. CartPromotionService opens one transaction and locks the owner cart; absent/empty carts fail with 409.
3. The repository reads items/current products without product locks. CartPricingService checks integer totals and purchasability.
4. PromotionService resolves the code and checks active state, UTC `[starts_at, expires_at)`, minimum subtotal, and global/customer ledger counts.
5. PromotionCalculator calculates the capped half-up integer discount. Only successful eligibility/calculation permits replacement of `promotion_id`.
6. Return the updated cart resource. Any failure rolls back and preserves the prior code. No stock, counters, or ledger writes occur.
7. DELETE locks the owner cart and detaches the selection; repeated removal and absent carts return 204 without creating state.
8. GET and item mutation responses re-evaluate eligibility. Invalid selections remain with zero discount.

Future checkout must repeat every check under locks; attachment is not a purchase guarantee.

## Checkout (planned; not implemented)

`CheckoutService` owns one database transaction and follows this deterministic sequence:

1. Lock the customer's cart row.
2. Load cart items; reject an empty cart.
3. Collect product IDs, sort ascending, and lock product rows in that order.
4. Validate active products and requested quantities against locked stock.
5. Recalculate unit prices, line totals, and subtotal using integer minor units.
6. If a promotion is attached, lock its row, then lock/read the customer's applicable usage aggregate in a consistent order.
7. Validate dates, status, threshold, global limit, and per-customer limit.
8. Calculate and cap the discount; derive final total.
9. Create the order and immutable order item snapshots.
10. Deduct each product's stock while rows remain locked.
11. Insert one `promotion_redemptions` record with the successful order FK and stable redemption key; no denormalized usage counter is used.
12. Delete cart items and detach the promotion.
13. Commit and return the newly created order.

Any exception rolls back the complete workflow. External calls must not occur inside this transaction.

### Lock order

`cart → products (ascending ID) → promotion → promotion/customer usage`

All workflows that touch these resources must follow the same relative order. PostgreSQL deadlocks can still occur, so a small bounded retry for detected deadlocks/serialization failures is proposed after tests demonstrate the need.

## View orders

1. Sanctum authenticates the customer.
2. Policy/scope constrains queries by authenticated customer ID.
3. Repository returns a paginated summary or one order with items.
4. Resources expose snapshots and totals, not internal control fields.

## Cancel order

`OrderService` owns one database transaction:

1. Resolve the order through the authenticated customer's scope.
2. Lock the order row.
3. If already cancelled, return it without further writes (proposed idempotent semantics).
4. Validate that its current status is cancellable.
5. Load order items, sort product IDs ascending, and lock product rows in that order.
6. Atomically mark the order `cancelled`, set `cancelled_at`, and set `inventory_restored_at`.
7. Increment stock by ordered quantities.
8. Commit and return the cancelled order.

The locked order row plus a non-null restoration marker prevents two requests from restoring stock twice. Promotion usage remains recorded under the current proposal.

## Failure handling

- Validation failures occur before a service call where possible and return `422`.
- Expected business conflicts raise typed domain exceptions, mapped centrally to stable error codes.
- Unknown failures return a generic `500` response with a correlation identifier and full server-side logging.
- Database lock timeouts/deadlocks never return partially committed state.
