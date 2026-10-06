# 08 — Business Rules

## Catalogue and inventory

- BR-P01: Public catalogue endpoints return only active products.
- BR-P02: SKU is required and unique under the proposed case-insensitive normalization.
- BR-P03: Product price and stock are non-negative integers.
- BR-P04: Cart quantity and order-item quantity are positive integers.
- BR-P05: A cart operation rejects a quantity above the currently visible stock but does not reserve it.
- BR-P06: Checkout revalidates stock while holding product row locks.
- BR-P07: Stock can never be negative; both service logic and a database check constraint enforce this.

## Cart

- BR-C01: A customer has at most one cart.
- BR-C02: A cart contains at most one line per product; adding the same product merges quantities.
- BR-C03: Only the authenticated owner may view or modify a cart/line.
- BR-C04: Cart monetary values are estimates calculated from current server-side product prices.
- BR-C05: Removing the final item leaves an empty cart rather than deleting the cart record (proposed).

## Promotions

- BR-R01: A promotion must be active and the checkout instant must fall within inclusive start/end bounds when present.
- BR-R02: Code matching is proposed as trimmed, uppercase, and case-insensitive unique.
- BR-R03: Only one promotion applies to an order.
- BR-R04: Minimum amount compares against the server-calculated pre-discount subtotal.
- BR-R05: Fixed discount is `min(value, subtotal)`.
- BR-R06: Percentage discount uses integer basis points. Proposal: round down to the nearest minor unit, then apply the maximum cap.
- BR-R07: Discount never exceeds subtotal; total never becomes negative.
- BR-R08: Nullable usage limits mean unlimited; non-null limits must be positive.
- BR-R09: A promotion usage is recorded only in the same successful transaction as its order.
- BR-R10: Cancellation does not release promotion usage (proposed and awaiting confirmation).

## Checkout and money

- BR-O01: Checkout requires an authenticated customer and at least one cart item.
- BR-O02: Client-supplied prices, discounts, subtotals, and totals are ignored or rejected.
- BR-O03: Product prices read under lock are the purchase prices.
- BR-O04: `line_total = unit_price × quantity`; `subtotal = sum(line totals)`; `total = subtotal - discount`.
- BR-O05: All monetary arithmetic uses integer minor units; floating point is forbidden.
- BR-O06: Inventory deduction, order/item creation, usage recording, and cart clearing either all commit or all roll back.
- BR-O07: Order items retain name, SKU, unit price, quantity, and line-total snapshots.
- BR-O08: A failed checkout leaves the customer's cart intact.

## Orders and cancellation

- BR-S01: Customers can list/view only their own orders.
- BR-S02: Proposed statuses are `pending`, `confirmed`, `processing`, `shipped`, `completed`, and `cancelled`.
- BR-S03: Proposed customer-cancellable statuses are `pending` and `confirmed`.
- BR-S04: `cancelled` is terminal in the MVP.
- BR-S05: Cancellation restores every ordered quantity exactly once.
- BR-S06: An order's `inventory_restored_at` must be null before restoration and set in the same transaction as stock increments.
- BR-S07: Repeating cancellation for an already-cancelled order returns success without modifying stock (proposed idempotent behavior).

## Authorization, errors, and privacy

- BR-X01: Cart, checkout, and order endpoints require a valid Sanctum token.
- BR-X02: A non-owned resource is treated as not found to avoid identifier enumeration (proposed).
- BR-X03: Expected business failures use stable machine-readable codes and safe messages.
- BR-X04: Validation can reveal field-specific errors; unexpected exceptions never reveal stack traces or SQL in production.
- BR-X05: Secrets, credentials, password values, and bearer tokens are never logged. A new bearer token is returned only in its successful registration/login issuance response; profiles and errors never expose it. Password values and hashes are never returned.

## Invariants requiring database support

- `products.stock_quantity >= 0`.
- Unique `products.sku` under chosen normalization.
- Unique `carts.user_id` and `(cart_id, product_id)`.
- Positive cart/order quantities.
- Promotion date range and type-dependent value validity.
- Unique `promotion_usages.order_id`.
- `0 <= discount_minor <= subtotal_minor` and `total_minor = subtotal_minor - discount_minor`.
- Once `inventory_restored_at` is set, cancellation logic must never increment stock again.
