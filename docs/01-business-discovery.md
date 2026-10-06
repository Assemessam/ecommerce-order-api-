# 01 — Business Discovery

## Purpose

The API provides a reliable backend for customers to discover products, maintain a cart, receive eligible promotions, place orders, inspect their orders, and cancel eligible orders. The assessment emphasizes correctness under concurrent demand rather than catalogue administration or payment processing.

## Actors

| Actor | Goal | Trust boundary |
|---|---|---|
| Guest | Browse and search the catalogue; create an account or sign in | Unauthenticated internet client |
| Authenticated customer | Manage only their own cart and orders | Sanctum-authenticated API client |
| Operations/support staff | Not in the current API scope | Future actor; no administrative endpoints are assumed |
| System/database | Preserve inventory, pricing, promotion, and order invariants | Internal trusted boundary |
| Assessment reviewer | Run, inspect, and verify the solution predictably | Development/test environment |

## Business outcomes

- A customer can find purchasable products with predictable filtering and pagination.
- A cart gives useful early stock feedback without reserving inventory.
- Checkout computes all monetary values on the server and commits atomically.
- Concurrent checkouts cannot oversell a product or exceed promotion limits.
- An accepted order is an auditable snapshot of what was purchased and for how much.
- Cancellation is idempotent with respect to inventory restoration.

## Confirmed constraints

- Backend REST API only; no frontend.
- Laravel 13, PHP 8.4.1+ for the committed dependency lock (Docker uses PHP 8.5), PostgreSQL, Eloquent, Sanctum, Pest, Composer, and Git.
- Controllers delegate validation to Form Requests and business rules to services.
- Major domains follow Controller → Service → Repository interface → Eloquent repository → PostgreSQL.
- `CheckoutService` owns checkout transactions; `OrderService` owns cancellation transactions.
- Prices and totals supplied by clients are never trusted.
- Money must not use floating-point arithmetic.
- Submission deadline: October 13, 2026.

## Proposed assumptions requiring review

These are design proposals, not silently invented requirements.

1. **Currency:** one deployment currency, configured as `USD`; mixed-currency carts are out of scope. Implication: amounts can be integer minor units without foreign-exchange logic.
2. **Identifiers:** PostgreSQL `bigint` identity keys. Implication: simple Eloquent integration; public opaque identifiers can be introduced later if enumeration risk matters.
3. **Cart reservation (confirmed in Milestone 3):** adding an item does not reserve stock. Implication: checkout can still fail if another customer buys the remaining stock.
4. **One active cart (confirmed in Milestone 3):** each customer has one cart. Implication: no saved/multiple-cart UI semantics.
5. **Price changes (confirmed for checkout in Milestone 5):** current product prices are authoritative at checkout. Implication: a cart display is an estimate and may change before purchase.
6. **Order lifecycle (Milestone 5 decision):** checkout creates `placed` orders. The earlier pending/confirmed/payment-style lifecycle is superseded. Milestone 6 approves only `placed → cancelled`; cancellation is terminal.
7. **Payment:** no gateway or payment state is included in the assessment. Checkout creates an order immediately when validation succeeds.
8. **Promotion stacking (confirmed in Milestone 4 for carts):** one promotion per cart/order. Implication: discount calculation and lock ordering remain deterministic.
9. **Tax and shipping:** excluded because neither was specified. Implication: `total = subtotal - discount` in the MVP.
10. **Promotion application (confirmed in Milestone 4):** a customer explicitly attaches/removes a code on the cart; final eligibility is always revalidated at checkout.
11. **Authentication (confirmed in Milestone 1):** public email/password registration and login issue Sanctum bearer tokens; POST logout revokes the current token; GET me returns the customer profile. Registration and login are rate limited. See the implemented contract in `07-api-contracts.md`.
12. **Order cancellation response (confirmed in Milestone 6):** repeated cancellation of an already-cancelled order returns the existing cancelled order successfully, while no inventory is restored twice.

## Open business questions

- Confirm currency and whether taxes, shipping, or inclusive pricing must be supported.
- Milestone 6 confirms `placed → cancelled`; additional lifecycle states remain outside scope.
- Milestone 6 confirms cancellation **does not release usage**: preserve the successful checkout ledger and historical discount.
- SKU normalization was confirmed in Milestone 2; Milestone 4 confirms trimmed uppercase promotion codes and canonical uniqueness enforced by PostgreSQL.
- Milestone 2 confirms that inactive products are hidden from both public list and direct-ID details (404). Active zero-stock products remain visible unless availability is filtered.
- Confirm retention, privacy, and customer-account deletion requirements.

## Success measures

- No negative stock or promotion counter beyond its limit in concurrency tests.
- Every order total is reproducible from stored order-item and discount snapshots.
- Cross-customer resource access is rejected without leaking existence.
- Foundation and business milestones pass automated checks from a clean setup.

Milestone 4 approves basis-point percentages, integer minor units, half-up rounding, inclusive start/exclusive expiry, ledger eligibility checks, and cart promotion APIs. Milestone 5 implements checkout, real order linking, redemption writes, and concurrent consumption. Milestone 6 implements exactly-once cancellation without releasing promotion usage.
