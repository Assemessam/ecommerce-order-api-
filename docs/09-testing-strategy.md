# 09 — Testing Strategy

## Goals

Tests demonstrate business correctness, authorization isolation, transaction atomicity, API contracts, and behavior under contention. Pest is the primary test runner.

## Test layers

### Unit tests

- Pure money and discount calculations, including rounding and caps.
- Promotion eligibility rules and validity-boundary times.
- Status-transition rules.
- DTO/enum behavior where it adds meaningful confidence.

These tests avoid the framework/database where possible.

### Feature/API tests

- Route authentication, Form Request validation, status codes, and JSON shapes.
- Catalogue search/filter/sort/pagination.
- Owner vs non-owner cart/order access.
- Cart merging, quantity updates, removals, stock feedback, and promotion attachment.
- Checkout success and every documented business failure.
- Cancellation success, invalid status, and repeated cancellation.

Use factories and `LazilyRefreshDatabase` (or `RefreshDatabase` when needed) for isolated tests. Database-sensitive feature tests run against PostgreSQL, not SQLite, because lock behavior and constraints are part of the product.

### Integration/repository tests

- Query semantics, case normalization, eager loading, and pagination.
- PostgreSQL check/unique/foreign-key constraints.
- Transaction rollback after injected failures at critical checkout steps.
- Lock acquisition and deterministic ordering.

### Concurrency tests

Run separate database connections/processes against PostgreSQL with barriers so requests genuinely overlap:

1. Stock 5; checkout quantities 4 and 3 concurrently; assert at most one incompatible allocation succeeds and final stock is never negative.
2. Promotion global limit 1; two eligible customers check out concurrently; assert one usage/order discount succeeds.
3. Per-customer limit 1; the same customer issues overlapping eligible checkouts; assert the limit holds.
4. Two cancellation requests overlap; assert stock increases once and both responses follow the chosen idempotency contract.
5. A forced exception after stock update but before order completion rolls the entire checkout back.

These are not simulated by calling service methods sequentially in one connection.

## Foundation tests

- `GET /api/health` returns the exact stable JSON response.
- Base migrations run on PostgreSQL.
- Application boots and route registration succeeds.

## Security tests

- Missing, invalid, and revoked tokens.
- Cross-customer identifiers on cart items and orders.
- Mass-assignment attempts and unrecognized inputs.
- Sort-column injection and malformed numeric filters.
- Authentication rate limiting and password/token non-disclosure.
- Production error response does not expose exception details.

## Quality gates per milestone

```bash
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api php artisan test --compact
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
```

Database/concurrency suites run inside Compose. Coverage is useful for finding gaps, but no arbitrary percentage replaces scenario coverage. The checkout and cancellation critical paths require branch/failure-path coverage.

## Test data and time

- Factories produce valid defaults; tests override only relevant fields.
- Freeze time for promotion boundary tests.
- Each test owns its records and does not depend on execution order.
- Never use production data or secrets.

## Current verified scope

Milestone 1 adds `tests/Feature/Http/Controllers/Api/AuthControllerTest.php` and `tests/Feature/ApiErrorResponseTest.php`, alongside the unchanged health and framework examples. Authentication coverage includes registration, normalized duplicate email, field validation, configured password strength and byte boundaries, hash-shaped input, login/hash upgrade, missing/invalid/revoked tokens, public profile allow-list, session rejection, current-token logout, independent device/customer tokens, registration rollback, and throttling/reset behavior. Error-contract coverage includes JSON without Accept, safe status mappings, duplicate persistence conflicts, request IDs/log context, no-store responses, and safe 500 responses with debug disabled and enabled.

Run focused authentication tests:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/AuthControllerTest.php
```

Run authentication plus API error tests:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/AuthControllerTest.php tests/Feature/ApiErrorResponseTest.php
```

`phpunit.xml` sets both `<env>` and `<server>` database variables because PHP server variables inherited from Docker can take precedence over PHPUnit environment overrides. `DB_URL` is forced empty. `Tests\TestCase::createApplication()` validates the testing environment, pgsql connection, Compose host/port, dedicated database name, and absence of a URL override before any database refresh. One test verifies the live PostgreSQL database name. Run these tests in the app container, which provides `pdo_pgsql`; host PHP currently lacks it. The project database publishes no host port and does not use `postgres-local`.

Token lifecycle tests use actual persisted Sanctum tokens and Authorization headers. Between sequential protected requests they clear cached authentication guards to model independent HTTP requests. Rate-limit tests freeze/advance time without sleeping; test cache is isolated per application. Transactions roll back test records.

No static analysis tool is installed or configured. Commerce and real concurrency tests remain planned for later milestones and are not claimed as implemented.


## Milestone 1 executed verification

On October 6, 2026, using the existing Compose app container:

| Check | Result |
|---|---|
| Preflight `php artisan test --compact` | 3 passed, 4 assertions |
| Authentication and API error test files | 59 passed, 302 assertions |
| Complete `php artisan test --compact` | 62 passed, 306 assertions |
| `vendor/bin/pint --dirty --format agent` | Passed; corrected import ordering |
| `composer validate --strict` | Valid |
| `composer audit` | No security vulnerability advisories |
| API route inspection | Health plus the four required auth endpoints |

The development database remained at zero users and zero tokens. `postgres-local` remained running with the same container ID/start time and zero restarts. No Docker configuration, dependency, migration, commit, or push was introduced by Milestone 1.
