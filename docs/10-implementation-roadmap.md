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

## Milestone 6 — Orders and cancellation (implemented)

- Approved `placed → cancelled`, terminal cancellation, no release of promotion usage.
- Implemented owner-scoped list/detail and `OrderService` cancellation transaction.
- Added restoration timestamps, state constraints, history index, repeated/concurrent cancellation, rollback, overflow, and checkout-replay tests.

Review gate: inventory restores exactly once.

## Milestone 7 — Hardening and submission (completed)

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

## Bonus Milestone 7A — Admin product and promotion management (implemented)

- Minimal guarded is_admin flag, Sanctum and class-level policies, local-only existing-account provisioning.
- Thin admin controllers, specific Form Requests, existing resources/enums/repositories, dedicated administration services.
- Atomic Product row-locked signed adjustments and Promotion row-locked edits/consumed-limit checks.
- Historical snapshot/ledger preservation, actual PostgreSQL contention, regression and security verification, documentation and separate Admin Postman examples.

Bonus 7A stops after verification. The next **separately authorized** milestone may implement Redis public-catalogue caching. Required invalidation points are documented in [14-admin-management.md](14-admin-management.md); no caching, order queues or additional rate limiting is started here. The other optional bonus milestones need their own scope/approval.

## Bonus Milestone 7B — Redis public catalogue caching (implemented)

- Dedicated private Compose Redis service and PhpRedis image support, independently configured catalogue store/namespace/TTL.
- Focused service-layer read-through caching preserving validated queries, resource responses, pagination and PostgreSQL repository ownership.
- Generation-based post-commit invalidation across product administration, checkout inventory deduction, cancellation restoration and new sample seeding.
- Transactional-read bypass, rollback/race/outage tests, bounded diagnostics and committed-purchase safety; unchanged authoritative checkout/cancellation locks and idempotency.
- Real Redis/PostgreSQL tests, complete regression, Redis-enabled independent-process contention, repeatable local benchmarks, Pint/Composer/whitespace verification.

Evidence and known limits are in [15-redis-caching.md](15-redis-caching.md). The 7B scope ended with catalogue caching verification. Durable order notifications are implemented in the separately scoped 7C milestone below.

## Bonus Milestone 7C — Order events and background queues (implemented)

- PostgreSQL transactional outbox for placement/cancellation; immutable UUID envelopes and unique order/type identities.
- Bounded concurrency-safe claims, dedicated Redis queue, expiring ownership and crash/broker-loss recovery.
- Laravel events, a lightweight job and local durable notification listener; transactional result/completion and idempotent effects.
- Bounded processing retries, native failed jobs, sanitized status diagnostics and safe manual outbox retry.
- Opt-in Compose worker/scheduler, PostgreSQL constraints and real Redis/independent-process tests.
- Original checkout/cancellation inventory, promotion, status and replay behavior preserved.

The implementation and verification are documented in [16-order-events-queues.md](16-order-events-queues.md). 7C was reviewed and committed on `feature/order-events-queues-7c` as `49732ad851d8163e47b91be2979a0ffda8cc974d`. Bonus 7D starts directly from that commit on its own branch.


## Bonus Milestone 7D — API rate limiting (implemented)

- Configurable Laravel named policies for registration/login, public reads, customer reads/mutations, checkout/cancellation, admin reads/mutations and account access.
- Dedicated Redis DB/prefix, native atomic Lua admission, private infrastructure and explicit trusted-proxy configuration.
- Existing JSON 429/request ID and correct native rate-limit/retry headers; sanitized fail-closed 503 during limiter outages.
- Account-targeted login protection, authenticated customer/admin isolation and unchanged business authorization.
- Purchase replay remains throttled; rejected checkout/cancellation has no database/outbox effects. Queue/recovery operations are independent.
- Real Redis HTTP/security/checkout and forced independent-process concurrency tests, complete PostgreSQL regression and existing contention/cache/queue/outbox verification.

See [17-api-rate-limiting.md](17-api-rate-limiting.md) for current results and file inventory. 7D is intentionally uncommitted on `feature/api-rate-limiting-7d`. Stop after verification: no merge, push, deployment, final submission or unrelated feature work.
