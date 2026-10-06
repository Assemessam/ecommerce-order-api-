# 02 — Requirements

## Functional requirements

### Catalogue

- FR-P01: Return a paginated list of active products.
- FR-P02: Return one active product by identifier.
- FR-P03: Search products by name, SKU, and description.
- FR-P04: Filter by minimum/maximum price and availability.
- FR-P05: Sort only by an allow-list of fields and directions.

Milestone 2 implements FR-P01, FR-P02, FR-P04, and FR-P05. The explicit milestone request limits FR-P03 delivery to case-insensitive product **name** search. The original broader name/SKU/description requirement above remains unchanged for traceability; SKU/description search is deferred. Storage uses the approved `price_minor` column, while the public resource calls its money object `price`.

Implemented catalogue contract: active-only visibility; zero-stock active products visible by default; `available=true` means positive stock, `available=false` means zero stock; inclusive integer minor-unit prices; sorts `name`, `price`, `created_at` with `asc`/`desc` and an ID tie-breaker; default page size 15, maximum 100. Invalid query inputs return 422 in the Milestone 1 envelope. See `07-api-contracts.md` for exact limits and response examples.

### Authentication and authorization

- FR-A01: Authenticate customers with Laravel Sanctum tokens.
- FR-A02: Require authentication for cart, checkout, and order routes.
- FR-A03: A customer can read or mutate only resources they own.
- FR-A04: Revoke the current access token on logout.

Milestone 1 confirms public POST registration/login, authenticated POST logout, and authenticated GET current customer. See `07-api-contracts.md` for the implemented contract.

### Cart

- FR-C01: Return the authenticated customer's cart.
- FR-C02: Add an active product with a positive integer quantity.
- FR-C03: Merge with an existing line for the same product rather than create duplicates.
- FR-C04: Update a line to a positive integer quantity.
- FR-C05: Remove a line.
- FR-C06: Reject a requested quantity greater than currently available stock.
- FR-C07: Attach or remove one promotion code (proposed behavior).

### Promotions

- FR-R01: Support percentage and fixed promotions.
- FR-R02: Enforce active flag, validity interval, minimum subtotal, optional maximum discount, global usage limit, and per-customer usage limit.
- FR-R03: Recalculate and revalidate a promotion during checkout.
- FR-R04: Record successful use against both the customer and order.

### Checkout and orders

- FR-O01: Reject an empty cart.
- FR-O02: Re-read products and authoritative prices inside checkout.
- FR-O03: Lock contested resources in deterministic order.
- FR-O04: atomically deduct stock, create the order and item snapshots, record promotion usage, and clear the cart.
- FR-O05: Return a customer's paginated order history and individual order detail.
- FR-O06: Preserve product name, SKU, unit price, quantity, and line total on every order item.
- FR-O07: Cancel an eligible order atomically and restore stock once.
- FR-O08: Make repeated cancellation safe and prevent duplicate restoration.

## Non-functional requirements

- NFR-01 Correctness: inventory and promotion limits remain valid under concurrent requests.
- NFR-02 Consistency: writes that form one business outcome use one PostgreSQL transaction.
- NFR-03 Security: hashed passwords, Sanctum token protection, authorization policies, mass-assignment controls, rate limits, and no secret values in version control.
- NFR-04 Performance: paginated collections, indexed lookup/filter columns, bounded page sizes, eager loading, and no unbounded scans in request paths.
- NFR-05 Maintainability: PSR-12, Laravel conventions, SOLID dependencies, small controllers, domain services, repository contracts, DTOs where useful, and backed enums for closed states.
- NFR-06 Observability: structured application logs with request/correlation context; never log tokens, passwords, or unnecessary personal data.
- NFR-07 Portability: reproducible Docker Compose development runtime and documented native prerequisites.
- NFR-08 Testability: deterministic unit, feature, integration, and contention tests using Pest.
- NFR-09 API stability: consistent JSON envelopes, error codes, ISO-8601 timestamps, and backwards-compatible evolution within the assessment.
- NFR-10 Recovery: failed transactions leave no partial order, stock deduction, promotion use, or cart clearing.

## Acceptance criteria

- Catalogue queries reject unsupported sort/filter input with `422` rather than interpolate arbitrary columns.
- An unauthenticated cart request returns `401`.
- A customer requesting another customer's nested resource receives `404` under the proposed non-enumeration policy.
- Adding or updating beyond visible stock returns `409` with `INSUFFICIENT_STOCK`.
- Two checkouts competing for insufficient stock result in at most one successful allocation; stock never becomes negative.
- The same promotion cannot exceed global or per-customer limits under concurrent checkouts.
- No order accepts client-provided unit prices, discounts, subtotals, or totals.
- Any checkout failure rolls back all checkout writes and leaves the cart intact.
- Cancelling twice restores inventory exactly once.
- Foundation health endpoint returns `200` with `{"data":{"status":"ok"}}`.

## Traceability

Implementation milestones and tests should cite requirement IDs in pull-request descriptions or test names where practical. Requirements whose behavior remains proposed must be confirmed before their implementation milestone.
