# E-Commerce Order & Promotion API

Laravel REST API for a Senior Laravel Developer assessment: product discovery, customer authentication, carts, promotions, atomic checkout, historical orders, and cancellation. The implementation prioritizes exact money calculations, customer isolation, and PostgreSQL concurrency correctness. Submission deadline: October 13, 2026.

The mandatory-scope audit is in [docs/13-final-audit.md](docs/13-final-audit.md). Secure product/promotion administration is in [docs/14-admin-management.md](docs/14-admin-management.md); Redis catalogue caching verification is in [docs/15-redis-caching.md](docs/15-redis-caching.md); durable order events/queues are in [docs/16-order-events-queues.md](docs/16-order-events-queues.md). The separate employer brief is not present in this repository; the audit uses [the recorded requirements](docs/02-requirements.md) and the Milestone 7 checklist.

## Stack and prerequisites

- PHP **8.4.1+ for the committed dependency lock**; the supplied Docker image uses PHP 8.5.
- Laravel 13.34.0, Sanctum 4.3.3, PostgreSQL 17, Redis 7.4, PhpRedis 6.3.0, Pest 4.7.8, Composer 2.
- Docker Engine with Compose v2 and Git are the recommended local prerequisites.
- Native execution requires 64-bit PHP, Composer, a PostgreSQL instance, and the extensions reported by `composer check-platform-reqs`, plus `pdo_pgsql` for this application and the supplied image's `intl`/`pcntl` development tooling support. PHP 8.3 cannot install the locked Symfony 8.1 dependencies even though the root Composer constraint allows it.
- Node/npm and frontend builds are unnecessary for this JSON API.

## Installation and database setup

```bash
git clone <repository-url> ecommerce-order-api
cd ecommerce-order-api
cp .env.example .env
docker compose build api
docker compose run --rm api composer install --no-interaction
docker compose run --rm api php artisan key:generate --no-interaction
docker compose run --rm api php artisan migrate --no-interaction
# Optional sample products and promotions; these preserve existing records:
docker compose run --rm api php artisan db:seed --class=ProductSeeder --no-interaction
docker compose run --rm api php artisan db:seed --class=PromotionSeeder --no-interaction
docker compose up -d api
```

Compose starts its healthy PostgreSQL and dedicated Redis dependencies automatically. The API listens at **http://localhost:8091** by default:

```bash
curl -H 'Accept: application/json' http://localhost:8091/api/health
# {"data":{"status":"ok"}}
```

On Linux the bind-mounted files must be writable by the container user (default UID/GID 1000). Override the Compose user for one-off setup commands with `--user "$(id -u):$(id -g)"` if your host IDs differ. No production web server is included: `artisan serve` is a development runtime.

### Environment

| Setting | Local behavior |
|---|---|
| `APP_KEY` | Generated above; never commit it |
| `APP_ENV`, `APP_DEBUG` | Local defaults; use production / false outside development |
| `APP_URL`, `APP_PORT` | Default host URL and port 8091; change both when changing the port |
| `CATALOGUE_CURRENCY` | One uppercase three-letter currency label; defaults to USD; no currency conversion |
| `DB_*` | Compose explicitly supplies pgsql / postgres:5432 / ecommerce_order_api / ecommerce / local-only password `secret` |
| `CACHE_STORE` | Database cache, shared by authentication rate limiters |
| `CATALOGUE_CACHE_ENABLED`, `CATALOGUE_CACHE_STORE` | Public listing cache enabled; dedicated `catalogue` Redis store |
| `CATALOGUE_CACHE_TTL` | 45-second freshness budget; configurable, clamped to 1–300 seconds; slow-read time is deducted |
| `CATALOGUE_CACHE_NAMESPACE`, `CATALOGUE_CACHE_PREFIX`, `REDIS_PREFIX` | Separate application/environment namespaces; configure unique values when sharing Redis |
| `REDIS_HOST`, `REDIS_PORT`, `CATALOGUE_REDIS_DB` | Compose `redis:6379`, catalogue DB 2; tests force DB 3 |
| `REDIS_USERNAME`, `REDIS_PASSWORD`, `CATALOGUE_REDIS_URL` | Optional private credentials/authenticated TLS URL; keep secrets outside Git |
| `CATALOGUE_REDIS_TIMEOUT` | 0.2-second connection/read timeouts; no catalogue client retries |
| `ORDER_EVENTS_QUEUE_CONNECTION`, `ORDER_EVENTS_QUEUE` | Dedicated `order-redis` queue connection and `order-events-local` queue in Compose |
| `ORDER_EVENTS_REDIS_DB`, `ORDER_EVENTS_REDIS_URL` | Separate queue DB 4 (test DB 5); optional private queue Redis URL |
| `ORDER_EVENTS_LEASE_SECONDS`, `ORDER_EVENTS_DISPATCH_RETRY_SECONDS`, `ORDER_EVENTS_BATCH_SIZE` | Recoverable 300-second claims, 15-second broker retries and bounded 100-event relay batches |

Compose environment settings override `.env` database values. Native execution requires a reachable PostgreSQL host and your own database credentials. The supplied tests intentionally require the isolated Compose host and dedicated test database; native test execution without an equivalent setup is unsupported.

PostgreSQL has **no published host port**. Its network and `postgres_data` volume belong to this Compose project. The initialization SQL creates `ecommerce_order_api` and the separate `ecommerce_order_api_test` database on a fresh volume. Existing volumes without the test database require explicit provisioning of that test database; initialization scripts do not rerun on an existing volume. Tests refuse unexpected targets before refreshing tables.

### Redis setup and degraded operation

For an existing checkout, rebuild PHP and start only this project's services:

```bash
docker compose build api
docker compose up -d --no-deps redis api
docker compose exec -T api php --ri redis
docker compose exec -T redis redis-cli ping
# If configuration was previously cached:
docker compose exec -T api php artisan config:clear --no-interaction
```

Redis uses the project's private default bridge network, exposes no host port, disables persistence, and limits memory to 128 MB with allkeys-lru eviction. There is no Redis volume; Bonus 7C adds two PostgreSQL outbox/result migrations. Compose overrides REDIS_HOST with `redis`; native execution needs PhpRedis and a reachable Redis host or private `CATALOGUE_REDIS_URL`. Do not stop/reconfigure other Redis or PostgreSQL projects. Production Redis access should use private networking and privately supplied authentication/TLS settings.

Only `GET /api/products` (and its HEAD route) uses this cache. The service stores scalar product snapshots and totals, and the existing resource/controller builds current pagination links and JSON. Keys hash validated normalized filters, sort, page, and page size under a versioned public generation. Details and administrator listings remain live. Successful product edits/creation, stock adjustment, checkout, cancellation, and new sample seeding rotate the generation after commit; rollback and purchase/cancellation replays do not rotate it.

Listings are short-lived estimates. A request already in flight may return its earlier read; failed invalidation or process failure after commit can leave earlier entries reachable until their short TTL expires. Generation changes prevent a late old reader from publishing into the current namespace. Redis outages fall back to PostgreSQL without exposing connection details or changing committed purchase results. Warnings are limited to one per namespace/minute per application filesystem; subsequent cache attempts are skipped for that request. Checkout prices, stock, locks, snapshots, promotions, and idempotency remain authoritative in PostgreSQL. Set `CATALOGUE_CACHE_ENABLED=false` and clear cached configuration to disable listing caching.

Do not reset the development database or modify `postgres-local` or unrelated Docker projects. A second checkout can use a distinct Compose project name and API port for a disposable rehearsal. The optional sample seeders insert six products and seven promotions without resetting prices, stock, customers, or existing promotions. Register your own customer through the API; the default `DatabaseSeeder` creates a fixed demo customer and is deliberately not part of this setup.

## Order events and background workers (Bonus 7C)

Checkout and cancellation atomically record `OrderPlaced` / `OrderCancelled` envelopes in a PostgreSQL outbox. A bounded relay claims due rows with `FOR UPDATE SKIP LOCKED`, then pushes ID/token jobs to an independent Redis queue after its transaction commits. Workers create an internal notification/audit result and mark processing complete together. Unique event/result constraints and ownership tokens protect against duplicate and stale jobs. Workers never change stock, order status or promotion usage.

```bash
docker compose exec -T api php artisan migrate --no-interaction
# Start only the optional order services after migration:
docker compose --profile orders up -d --no-deps order-worker order-scheduler
docker compose exec -T api php artisan orders:outbox --status=pending --no-interaction
docker compose exec -T api php artisan queue:failed --no-interaction
docker compose exec -T api php artisan orders:retry-outbox EVENT_UUID --no-interaction
docker compose exec -T api php artisan orders:dispatch-outbox --no-interaction
docker compose --profile orders stop order-worker order-scheduler
```

Redis outages preserve committed orders. Expiring PostgreSQL leases recover lost jobs/crashed workers; three normal attempts with 5/30-second backoff precede failure. Manual outbox retry refreshes ownership; native `queue:retry` alone cannot reset a failed event. Delivery is at least once with effectively-once internal effects. Events may process out of order. The local Redis instance remains disposable and shares eviction/memory across catalogue and queue DBs; a separate durable queue Redis is recommended for production. Worker startup, direct one-pass commands, diagnosis, recovery and exact verification evidence are in [docs/16-order-events-queues.md](docs/16-order-events-queues.md).

## Tests and quality checks

Run database suites **sequentially**. The contention tests use committed fixtures and migration teardown, so concurrent suite invocations against the same test database are unsupported. Do not use `--parallel` with the guarded single-database configuration.

```bash
docker compose exec -T api php artisan test --compact
# PostgreSQL + real Redis functional, invalidation, rollback, race and outage tests:
docker compose exec -T api php artisan test --compact tests/Feature/Services/Product/ProductCatalogueCacheTest.php
# PostgreSQL outbox and real Redis jobs/workers, retries, recovery and crash checks:
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Order/OrderOutboxServiceTest.php \
  tests/Feature/Jobs/ProcessOrderEventTest.php \
  tests/Feature/Services/Order/OrderOutboxConcurrencyTest.php \
  tests/Feature/Models/OrderEventPersistenceTest.php
# Repeatable local HTTP-kernel benchmark with 200 isolated test products:
docker compose exec -T api php artisan test --compact --group=catalogue-benchmark
# Standalone contention and injected retry/exhaustion cases:
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
git diff --check
```

HTTP/service/repository/constraint tests use real PostgreSQL; the calculator is tested independently with exact expected integers. Fault injection covers rollback and normally unreachable corrupt/missing states. Real contention tests use separate PHP processes and PostgreSQL connections, an independent observer, and two observed lock waiters before barrier release. They exercise authenticated HTTP kernel requests; they are not a network load benchmark. Injected SQLSTATE deadlock tests establish retry behavior, not observed deadlock cycles. No static analyzer is installed; see the audit's Larastan assessment.

The complete suite now requires the project Redis service. Existing tests disable catalogue caching by default; caching tests explicitly enable it with a unique namespace in Redis DB 3. Cleanup deletes only that namespace's keys and never calls FLUSHDB/FLUSHALL. The benchmark uses 25 uncached and 25 warm samples per listing variant, reports actual timings and product query counts, and removes its PostgreSQL/Redis fixtures. Local measurements are not production capacity estimates. Redis-enabled independent-process concurrency verification is described in [the bonus report](docs/15-redis-caching.md).

## API documentation and Postman

[docs/07-api-contracts.md](docs/07-api-contracts.md) contains payloads, resources, pagination, validation, and domain error codes. Import [the Postman collection](postman/Ecommerce_Order_Promotion_API.postman_collection.json), set `base_url` without `/api`, and supply local `customer_email` and a strong `customer_password`. Keep credentials and captured `bearer_token` private; clear them before exporting or sharing. The collection captures usable product/cart/order IDs and tokens, and preserves the checkout key for retries. Clear `idempotency_key` before a new purchase.

The requests include an extra cart addition after deletion so the example checkout has an item. Sample flow expects the optional seeders, a fresh customer email, and a stocked product. Each request states its prerequisites and expected success status. Collection validation is reported separately from execution in the audit.

| Method | Endpoint | Auth | Success |
|---|---|---|---|
| GET | `/api/health` | Public | 200 |
| POST | `/api/auth/register` | Public | 201 customer + token |
| POST | `/api/auth/login` | Public | 200 customer + token |
| GET | `/api/auth/me` | Bearer | 200 customer |
| POST | `/api/auth/logout` | Bearer | 204 |
| GET | `/api/products` | Public | 200 paginated products |
| GET | `/api/products/{id}` | Public | 200 active product |
| GET | `/api/cart` | Bearer | 200 estimate |
| POST | `/api/cart/items` | Bearer | 201 cart; merges quantities |
| PATCH | `/api/cart/items/{id}` | Bearer | 200 cart |
| DELETE | `/api/cart/items/{id}` | Bearer | 204 |
| POST | `/api/cart/promotion` | Bearer | 200 cart |
| DELETE | `/api/cart/promotion` | Bearer | 204 |
| POST | `/api/checkout` | Bearer | 201 new order / 200 replay |
| GET | `/api/orders` | Bearer | 200 paginated summaries |
| GET | `/api/orders/{id}` | Bearer | 200 historical detail |
| POST | `/api/orders/{id}/cancel` | Bearer | 200, including repeat |
| GET | `/api/admin/products` | Admin bearer | 200, includes inactive |
| GET | `/api/admin/products/{id}` | Admin bearer | 200 |
| POST | `/api/admin/products` | Admin bearer | 201 |
| PATCH | `/api/admin/products/{id}` | Admin bearer | 200 |
| GET | `/api/admin/promotions` | Admin bearer | 200, usage counts |
| GET | `/api/admin/promotions/{id}` | Admin bearer | 200 |
| POST | `/api/admin/promotions` | Admin bearer | 201 |
| PATCH | `/api/admin/promotions/{id}` | Admin bearer | 200 |

GET routes also accept HEAD. All routes are named; inspect them with `docker compose exec -T api php artisan route:list --path=api --except-vendor -v --no-interaction`.

Catalogue search is a trimmed, case-insensitive **literal substring of name, SKU, or description**, with escaped `%`, `_`, and backslash. `search` is limited to 100 characters. Inclusive `min_price` / `max_price` accept nonnegative integer minor units; `available=true` selects positive stock, `false` selects zero stock. Inactive products are hidden even on direct lookup. Sorting allows name / price / created_at and asc / desc, with an ID tie-breaker. Catalogue and history default to 15 entries, maximum 100; history order is fixed newest first. Unknown query keys are ignored and omitted from links; GET bodies cannot override filters.

Registration requires name, email, password, and password_confirmation. Emails normalize to lowercase. Passwords require 12+ characters, mixed case, a digit and symbol; bcrypt's 72-byte limit and null-byte rejection apply. Login accepts existing credentials without imposing the registration strength rules again. Optional device_name labels the token. Sanctum hashes tokens at rest, authenticates bearer tokens only, and logout revokes the current token without affecting other devices. Registration/login are rate limited; tokens otherwise retain the documented no-expiry and wildcard-ability policy; administrator access additionally requires the current stored administrator flag.

Cart IDs are inferred from the token. Item URLs identify cart lines, not products. POST adds to a quantity; PATCH replaces it. Product IDs and quantities must be actual positive JSON integers. Extra identity/price/total fields cannot alter the purchase. Missing/foreign resources return indistinguishable 404 errors. An absent cart reads as an empty estimate without creating a record. Cart edits never reserve or change inventory.

Successes use `data`, plus `links` and `meta` for paginated collections; 204 responses have no body. Errors always use JSON, including without an Accept header:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","details":{"fields":{"quantity":["The quantity field is required."]}},"request_id":"<uuid>"}}
```

Authentication is 401, ownership/missing resources 404, validation 422, business/stock/state conflicts 409, and authentication throttling 429 with Retry-After. Unexpected failures are generic 500 without SQL or traces even in local debug mode. API responses include X-Request-ID and Cache-Control: no-store, private. Resource fields are explicitly allow-listed.

## Service + Repository architecture

```text
Controller → Service → Repository interface → Eloquent repository → Model → PostgreSQL
```

Form Requests validate input; controllers select HTTP responses; services own business rules and transaction boundaries; repositories encapsulate queries, row locks, and writes. Repository contracts are bound in AppServiceProvider. Checkout and cancellation coordinate several repositories inside one service transaction; repositories do not independently commit workflows. Pricing/eligibility calculations are shared by estimates and checkout. Native Sanctum issuance/revocation is a documented framework persistence exception inside AuthService. Detail relationships are eager-loaded; order summaries do not query live catalogue data.

Architecture decisions are in [docs/05-architecture.md](docs/05-architecture.md), schema/constraints in [docs/06-database-design.md](docs/06-database-design.md), and detailed transaction discussions in [checkout](docs/11-checkout.md) and [order management](docs/12-order-management.md). Earlier milestone verification sections are historical; the final audit is the current evidence.

## Money, checkout, and concurrency

Money is a signed 64-bit integer in minor units. Product/cart/order money objects use `amount_minor` and `currency`; order-item snapshot fields are `unit_price_minor` and `line_subtotal_minor`, using the order's stored currency. USD 1899 means $18.99. Percentage values are integer basis points (2000 = 20%). The calculator uses quotient/remainder arithmetic with deterministic half-up rounding, then applies the optional cap and subtotal clamp. Line multiplication, subtotal addition, and stock restoration reject overflow before PHP can promote arithmetic to float. Discount never exceeds subtotal; total never goes negative. Zero-price products are permitted; promotion values/caps/limits must be positive when provided.

Checkout owns one PostgreSQL transaction, with up to three attempts for detected concurrency failures:

1. Lock the authenticated customer's cart and check the optional customer-scoped checkout key.
2. Reject an empty cart; lock all products in ascending ID order and revalidate active status, current stock, and server prices.
3. Lock the selected promotion, then revalidate dates, minimum subtotal, activity, and global/customer ledger counts.
4. Create order and item snapshots, conditionally deduct stock, write one redemption, and clear cart items and selection.
5. Commit together; any failure rolls back order, items, inventory, usage, and cart changes.

The stock decrement requires `stock_quantity >= quantity`; PostgreSQL also enforces nonnegative stock. The **Cart → Products ascending ID → Promotion** order reduces lock inversions. The promotion lock covers count, ledger insert, and commit, including first use by a customer. Every supported redemption writer must follow this protocol. Estimates are unlocked and can change; selecting a promotion reserves neither inventory nor usage. PostgreSQL Read Committed is the tested isolation level.

`Idempotency-Key` is optional, customer-scoped, case-sensitive, 1–128 ASCII characters, starting with a letter/digit and then letters/digits/period/underscore/colon/hyphen. A successful key permanently replays its original order with 200, including after cancellation or cart refill, without changing the new cart or consuming stock/usage again. New purchases require new keys. Checkout accepts no purchase body, so there is no alternate payload fingerprint contract. Failed transactions reserve no key; concurrent identical requests serialize on the cart and produce one purchase.

Orders preserve purchase names, SKUs, quantities, unit prices, line subtotals, totals, currency, and promotion calculation inputs. Product/promotion edits never recompute historical orders. Database checks enforce component arithmetic; the service enforces order subtotal equals the sum of item snapshots.

Cancellation supports **placed → cancelled** only. OrderService locks the owned order, checks eligibility under that lock, locks products ascending, and restores snapshot quantities with overflow-safe conditional increments. Status and equal cancellation/restoration timestamps commit with all stock changes. A repeated cancellation returns the original persisted markers without another increment. **Order → Products ascending ID** introduces no reverse Cart/Promotion acquisition. Cancellation retains promotion usage and historical discounts.

## Administrator product and promotion management (Bonus 7A)

Apply the additive migration with `docker compose exec -T api php artisan migrate --no-interaction`. It adds `users.is_admin`, default false for existing and new customers. Registration cannot grant this flag, and User mass assignment excludes it. All eight admin routes require Sanctum plus Product/Promotion policies: guests get 401 and ordinary customers get 403. Customer ownership policies still apply to administrators on customer routes.

Provision an administrator **only in local development**: register your own account with a private password, then run this command for that existing email while APP_ENV=local (replace the example email with your registered local email):

```bash
docker compose exec -T api php artisan admin:grant developer@example.test --no-interaction
```

The command refuses every other application environment and missing users, creates no credentials, and changes no password/token. Log in through the existing auth endpoint. The separate Admin Postman folder captures `admin_bearer_token` using empty-by-default `admin_email` / `admin_password` variables. Production provisioning and a dedicated revocation workflow are outside this assessment feature.

Product POST accepts name, SKU, price_minor and optional description, stock_quantity and status. PATCH accepts property edits and a signed, nonzero **stock_adjustment**, applied to the locked current stock; absolute stock_quantity on PATCH is rejected. Prices and inventory use actual JSON integers with signed-bigint bounds. Example PATCH: `{"price_minor":2099,"stock_adjustment":-2,"status":"inactive"}`. A negative result or overflow returns 409 and rolls back all edits. Separate stock-adjustment requests are additive and not idempotent: don't blindly retry an uncertain response. Inactive products remain available to admin reads but hidden publicly; historical order items remain unchanged.

Promotion POST/PATCH use the existing percentage basis points/fixed minor-unit value, caps, validity, usage limits and is_active flag. Example PATCH: `{"type":"fixed","value":500,"is_active":false}`. Partial changes are checked against retained fields under the promotion lock. Supplied non-null limits below consumed global/largest individual customer usage return 409; equality is allowed and exhausts eligibility, null removes the limit. Edits affect future checkout; order snapshots and redemptions are preserved. Retirement uses deactivation; no hard-delete routes exist.

Transactions lock only the affected Product or Promotion row and keep it through commit, with bounded contention retries. Checkout/cancellation retain their original lock order, calculations and ledger rules. Independent PHP/PostgreSQL contention tests cover both queued orders for stock addition/removal, cancellation restoration, global/customer limit reductions, and discount changes. The complete report, API examples, known limitations, and future catalogue cache-invalidation map are in [docs/14-admin-management.md](docs/14-admin-management.md). Redis caching, order queues, and additional throttling are outside Bonus 7A.

## Trade-offs and known limitations

- No payment processing/refunds, shipping/tax integrations, admin order management, frontend, email verification/recovery, or additional lifecycle states.
- One currency, one cart/customer, one promotion/order, no inventory reservation, and bounded signed-bigint amounts. JavaScript clients must use lossless JSON handling for integers above 2^53−1.
- Popular product/promotion rows serialize short transactions. Substring search has no trigram index; ledger counts grow with history. No production-scale benchmark or universal deadlock-freedom claim is made.
- Per-customer usage enforcement has PostgreSQL sequential coverage and shares the tested promotion/cart locks; the harness does not isolate a concurrent per-customer-only limit scenario.
- Snapshot immutability, redemption identity consistency, and inventory restoration semantics assume supported service writers; privileged SQL can bypass them. Legacy redemption rows may have NULL order_id. Retention/account deletion policy is undecided; purchase-history foreign keys restrict deletion.
- The cancellation migration cannot downgrade while cancelled history exists: the earlier placed-only schema cannot represent it. Use forward fixes for retained data.
- Authentication-only throttles and indefinite customer tokens are explicit assessment policies. Production infrastructure, TLS, managed secrets, backups, and broader abuse controls require a separate operational review.
- The default customer seeder is not repeatable; use registration and the two explicit repeatable sample seeders above.

No commits, pushes, or deployments are performed by the audit. Review the final diff, reconcile the recorded requirements with the employer's original brief, and follow the submission checklist in docs/13-final-audit.md.
