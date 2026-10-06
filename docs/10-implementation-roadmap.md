# 10 — Implementation Roadmap

## Milestone 0 — Foundation (completed)

- Inspect host tools and compatibility.
- Install Laravel 13, Sanctum, and Pest.
- Configure PostgreSQL-oriented environment and Docker Compose.
- Add JSON health endpoint and automated test.
- Establish Service/Repository directory and binding conventions.
- Document requirements, workflows, schema, contracts, rules, tests, and trade-offs.

Exit gate: dependencies install, Compose services start, PostgreSQL migrations succeed, health request succeeds, tests and formatting pass, and the reviewer approves the assumptions.

## Milestone 1 — Authentication and API conventions (implemented; awaiting review)

- Confirm auth routes and token lifecycle.
- Implement Form Requests, mandatory auth service and user repository, resources, rate limits, and exception mapping.
- Add registration/login/logout/current-user tests and error-contract tests.

Review gate: security model, response envelope, and error mapping are frozen.

## Milestone 2 — Product catalogue (implemented; awaiting review)

- Add product enum, migration/constraints, model, factory, seeder.
- Add repository contract/implementation, query DTO, service, requests, resources, and controllers.
- Implement allow-listed search/filter/sort/pagination and tests.

Implemented: active-only public list/detail, name-only literal case-insensitive search, integer minor-unit filters, availability semantics, safe name/price/date ordering, stable pagination, PostgreSQL constraints, configured deployment currency, and repeatable standalone product seeding. Original FR-P03 SKU/description search is preserved but deferred by the explicit milestone scope. Verification results are recorded in `09-testing-strategy.md`.

Review gate: accept the catalogue contract and indexing plan before cart implementation. No cart, promotion, checkout, order, or stock-mutation functionality was added.

## Milestone 3 — Cart (implemented; awaiting review)

- Add cart/item schema and constraints.
- Implement owner-scoped repositories, service ownership/rules, requests, policies, resources, and endpoints.
- Test merging, stock feedback, isolation, and empty behavior.

Implemented: four Sanctum endpoints, one cart/customer, unique positive-quantity lines, read-only empty GET, additive/replacement quantities, current-price integer estimates, unavailable-line feedback, Cart → Product locking, safe first-cart creation, atomic failure handling, and independent PostgreSQL HTTP concurrency tests. No inventory reservations or stock changes. Verification is recorded in `09-testing-strategy.md`.

Review gate: accept cart contract, non-reservation semantics, and contention evidence before promotions.

## Milestone 4 — Promotions (implemented; stop after verification)

- Implement trimmed uppercase codes, integer basis points/minor units, half-up rounding, inclusive start/exclusive expiry, and no stacking.
- Add constrained promotion schema, prepared redemption ledger, enums, DTOs, services/repositories, factories/seeder, and tests.
- Implement authenticated POST/DELETE attachment/removal, live estimate eligibility, rollback, and real concurrent selection requests.
- Leave redemption writes, order linkage, and checkout consumption/concurrency for Milestone 5. Cancellation usage policy remains unresolved.

Review gate: promotion arithmetic and limits accepted before checkout integration.

## Milestone 5 — Checkout (implemented)

- Implement `CheckoutService` transaction, authoritative price calculation, deterministic locks, order snapshots, inventory deduction, promotion usage, and cart clearing.
- Add rollback and real concurrent PostgreSQL tests.
- Implement approved optional customer-scoped header keys, permanent original-order replay, and database uniqueness.

Review gate: contention tests prove no oversell/overuse and failure injection proves atomicity.

## Milestone 6 — Orders and cancellation

- Confirm statuses/cancellable states.
- Implement owner-scoped list/detail and `OrderService` cancellation transaction.
- Add restoration marker, status constraints, repeated/concurrent cancellation tests.

Review gate: inventory restores exactly once.

## Milestone 7 — Hardening and submission

- Run the full PostgreSQL suite, Pint, audit, and targeted performance/query reviews.
- Verify fresh-clone setup and migrations.
- Review authorization, secret handling, logging, indexes, README, API examples, and assumptions.
- Produce submission notes without introducing unreviewed features.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Ambiguous promotion/cancellation rules | Resolve open questions before their milestones; encode accepted decisions in tests |
| Race conditions hidden by SQLite | Run transactional and contention tests only against PostgreSQL |
| Deadlocks from inconsistent access | Global lock order, short transactions, targeted bounded retry if observed |
| Scope expansion before deadline | Preserve milestone gates and explicit out-of-scope list |
| Host lacks PostgreSQL PDO driver | Use the checked-in app container with `pdo_pgsql` |
| Client retries duplicate checkout | Decide idempotency-key behavior before checkout implementation |

## Next recommended milestone

Milestone 5 is implemented and stops here. The recommended next milestone is **Milestone 6: Orders and cancellation**: owner-scoped list/detail, approved cancellable states for the `placed` lifecycle, and exactly-once inventory restoration. No cancellation or next-milestone code is included. See `11-checkout.md` for checkout evidence.
