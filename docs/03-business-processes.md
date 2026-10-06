# 03 — Business Processes

## Browse catalogue

1. Client sends search/filter/sort/page parameters.
2. Form Request validates types, ranges, and allow-listed sort values.
3. Product service passes a query DTO to the repository.
4. Repository applies active-product visibility and returns a paginator.
5. Product resources return stable public fields and pagination metadata.

This is read-only and has no transaction boundary.

## Maintain cart

1. Sanctum authenticates the customer.
2. A Form Request validates product and quantity input.
3. Cart service loads or creates the customer's one active cart.
4. Product/cart repositories verify active status and current stock.
5. The service adds, merges, updates, or removes the line.
6. The API returns a recalculated cart estimate.

Cart checks provide early feedback but do not reserve stock. A later checkout can legitimately fail.

## Apply a promotion (proposed endpoint)

1. Customer submits a promotion code for their cart.
2. The service normalizes the code, loads the promotion, and evaluates preliminary eligibility against the current cart estimate.
3. The cart records the selected promotion reference.
4. The response shows an estimated discount.
5. Checkout repeats every eligibility check under locks; attachment is not a guarantee.

## Checkout

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
11. Insert the promotion-usage record and update any authoritative counter if the chosen schema uses one.
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
