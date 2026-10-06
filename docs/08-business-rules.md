# 08 — Business Rules

## Catalogue and inventory

- BR-P01: Public catalogue endpoints return only active products.
- BR-P02 (implemented in Milestone 2): SKU is required/nonblank; Eloquent trims and uppercases it. PostgreSQL uniqueness on `lower(btrim(sku))` rejects equivalent imports even when they bypass the model.
- BR-P03: Product price and stock are non-negative integers.
- BR-P04: Cart quantity and order-item quantity are positive integers.
- BR-P05: A cart operation rejects a quantity above the currently visible stock but does not reserve it.
- BR-P06: Checkout revalidates stock while holding product row locks.
- BR-P07: Stock can never be negative; both service logic and a database check constraint enforce this.

Milestone 2 confirms BR-P01..P03. Availability means **active and positive stock**; active zero-stock products remain visible by default, `available=false` selects only active zero-stock products, and inactive products never appear publicly, regardless of inventory. Inactive details return 404. Catalogue reads never reserve or deduct stock and require no transaction.

- BR-P08: Name search is a trimmed literal case-insensitive substring; empty means unfiltered. SKU/description search remains the original FR-P03 requirement outside this milestone's delivered scope.
- BR-P09: Price bounds are inclusive integer minor units matching `price.amount_minor`; negatives, decimals, overflow, and reversed ranges return 422.
- BR-P10: Public sorting permits only name, price, and creation date in ascending/descending order; ID breaks ties in the same direction. Default is newest first. Pagination defaults to 15 and rejects page sizes above 100.
- BR-P11: Product status is the closed `ProductStatus` enum (`active`, `inactive`), mirrored by a database CHECK. Database CHECKs and NOT NULL constraints preserve price/stock/status/SKU integrity independently of Eloquent.
- BR-P12: Sample catalogue seeding may insert missing demo SKUs but must not overwrite existing product inventory/prices or change customer records.

## Cart

- BR-C01: A customer has at most one cart.
- BR-C02: A cart contains at most one line per product; adding the same product merges quantities.
- BR-C03: Only the authenticated owner may view or modify a cart/line.
- BR-C04: Cart monetary values are estimates calculated from current server-side product prices.
- BR-C05: Removing the final item leaves an empty cart rather than deleting the cart record (implemented in Milestone 3).

Milestone 3 implements BR-C01..C05 and cart stock rule BR-P05. GET without a persisted cart creates no row. POST is additive; PATCH replaces a positive quantity and zero is invalid. The product must be active and the resulting line quantity must fit current stock at mutation time. Stock is never reserved, deducted, or restored by cart operations, including deletion.

- BR-C06: Existing unavailable lines remain visible with unchanged quantities. Reason precedence is inactive → out_of_stock → insufficient_stock; current-price totals still include them. Future modifications revalidate the affected product; DELETE remains available regardless of eligibility.
- BR-C07: No request can supply ownership or authoritative price/total/reassignment data. Missing and inaccessible item IDs return indistinguishable 404 errors. Inactive mutations return 409 PRODUCT_INACTIVE; stock failures return 409 INSUFFICIENT_STOCK.
- BR-C08: All cart mutations acquire the cart lock before dependent products/items, use one service transaction, and preserve database uniqueness. Concurrent first-cart creation and merging must not duplicate carts/lines or lose increments.
- BR-C09: Monetary multiplication/accumulation is checked before signed-bigint overflow. Overflow returns 409 CART_TOTAL_TOO_LARGE and mutation writes roll back; DELETE permits recovery. No rounded/float total is returned.

## Promotions

- BR-R01 (implemented for cart estimates): Active promotions require UTC `starts_at <= now < expires_at` when bounds exist; null bounds are unbounded. Checkout must independently revalidate.
- BR-R02: Codes are trimmed, uppercase, and canonical unique at the database boundary; 1..64 ASCII letters/digits/underscore/hyphen, beginning with letter/digit.
- BR-R03: Only one promotion is selected per cart; future orders also support one promotion.
- BR-R04: Minimum amount compares against the server-calculated pre-discount subtotal.
- BR-R05: Fixed discount is `min(value, optional cap, subtotal)`.
- BR-R06: Percentage discounts use integer basis points (1..10000), round half-up to the nearest minor unit, then clamp to cap/subtotal. The round-down proposal is superseded.
- BR-R07: Discount never exceeds subtotal; total never becomes negative.
- BR-R08: Nullable usage limits mean unlimited; non-null limits must be positive.
- BR-R09 (implemented in Milestone 5): Redemption is recorded only in the same successful checkout transaction as its real order. Applying/removing a cart code never consumes/restores/reserves usage.
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
- BR-S02: Milestone 5 checkout status is `placed`; the original pending/confirmed lifecycle proposal is superseded. Later status transitions remain unimplemented.
- BR-S03: Cancellation eligibility requires Milestone 6 approval for the `placed` lifecycle.
- BR-S04: `cancelled` is terminal in the MVP.
- BR-S05: Cancellation restores every ordered quantity exactly once.
- BR-S06: An order's `inventory_restored_at` must be null before restoration and set in the same transaction as stock increments.
- BR-S07: Repeating cancellation for an already-cancelled order returns success without modifying stock (proposed idempotent behavior).

## Authorization, errors, and privacy

- BR-X01: Cart, checkout, and order endpoints require a valid Sanctum token.
- BR-X02: A non-owned cart item is treated as not found to avoid identifier enumeration (implemented in Milestone 3); the same behavior remains proposed for future orders.
- BR-X03: Expected business failures use stable machine-readable codes and safe messages.
- BR-X04: Validation can reveal field-specific errors; unexpected exceptions never reveal stack traces or SQL in production.
- BR-X05: Secrets, credentials, password values, and bearer tokens are never logged. A new bearer token is returned only in its successful registration/login issuance response; profiles and errors never expose it. Password values and hashes are never returned.

## Invariants requiring database support

- `products.stock_quantity >= 0`.
- Unique `products.sku` under chosen normalization.
- Unique `carts.user_id` and `(cart_id, product_id)`.
- Positive cart/order quantities.
- Promotion date range and type-dependent value validity.
- Unique `promotion_redemptions.redemption_key` and unique real `order_id` FK (Milestone 5); order_id remains nullable for legacy ledger compatibility, but every checkout writer requires a real order.
- `0 <= discount_minor <= subtotal_minor` and `total_minor = subtotal_minor - discount_minor`.
- Once `inventory_restored_at` is set, cancellation logic must never increment stock again.

Milestone 4 implements BR-R01..R08 for estimates; BR-R09 is the future redemption contract, not an implemented checkout workflow. All cart lines must be purchasable and at least one line must exist. Invalid selections remain visible with zero discount; failed replacement preserves the original. Minimum compares pre-discount subtotal. Positive caps apply to both types. Global/customer limits count historical ledger rows (no pending reservations), and NULL limits remain unlimited. No inventory mutation or stacking is permitted.

Milestone 5 implements BR-O01..O08 and promotion consumption under BR-R09. The relative lock order is Cart → Products ascending ID → Promotion → ledger reads/writes, under PostgreSQL Read Committed. Initial status is placed; tax/shipping/payment are excluded and total = subtotal - discount. A selected eligible promotion creates one real order-linked redemption even when the clamped discount is zero. Without selection there is no redemption. Nullable legacy order links continue to count toward global/customer limits.

BR-O09: optional Idempotency-Key is scoped to the authenticated customer and permanently identifies one successful order. Replay returns the original order before empty-cart checks, even when the customer's cart has been refilled, without touching it. Failed attempts reserve no key. No body/query purchase parameters are supported, so conflicting client fields are ignored and cannot create a different purchase under that key. See `07-api-contracts.md` and `11-checkout.md`.
