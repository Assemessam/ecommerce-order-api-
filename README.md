# E-Commerce Order & Promotion API

Laravel REST API for a Senior Laravel Developer assessment: product discovery, customer authentication, carts, promotions, atomic checkout, historical orders, and cancellation. The implementation prioritizes exact money calculations, customer isolation, and PostgreSQL concurrency correctness. Submission deadline: October 13, 2026.

The project is published at [Assemessam/ecommerce-order-api-](https://github.com/Assemessam/ecommerce-order-api-). An independent review of published `main` at `834c17c97dbf77900369f9db1722f611376ad31e` reported **1,218 passing tests and 7,210 assertions**, including PostgreSQL/Redis integration and concurrency coverage. It also confirmed a successful fresh Docker installation, demo seeding, valid Postman artifacts, successful Newman workflow and negative-scenario runs, and no known Composer dependency vulnerabilities. These are the independent review's results for that commit; older milestone reports below retain their historical counts.

The ProductService and Spatie RBAC implementation, permission matrix, migration/provisioning procedure, and historical verification are in [docs/19-product-service-rbac-refactor.md](docs/19-product-service-rbac-refactor.md). The [DTO guide](app/DTOs/README.md) describes the later typed application inputs. The [structural integrity audit](docs/18-structural-integrity-audit.md) preserves its October 7 findings and identifies the superseding release architecture and verification. The [October 6 submission review](docs/18-final-submission-review.md) and earlier milestone reports remain historical evidence: [mandatory scope](docs/13-final-audit.md), [administration](docs/14-admin-management.md), [catalogue caching](docs/15-redis-caching.md), [order events/queues](docs/16-order-events-queues.md), and [API rate limiting](docs/17-api-rate-limiting.md). The separate employer brief is not present in this repository; the earlier submission review uses the supplied assessment summary and [the recorded requirements](docs/02-requirements.md).

## Stack and prerequisites

- PHP **8.4.1+ for the committed dependency lock**; the supplied Docker image uses PHP 8.5.
- Laravel 13.34.0, Sanctum 4.3.3, Spatie Laravel Permission 8.3.0, PostgreSQL 17, Redis 7.4, PhpRedis 6.3.0, Pest 4.7.8, Composer 2.
- Docker Engine with Compose v2 and Git are the recommended local prerequisites.
- Native execution requires 64-bit PHP, Composer, a PostgreSQL instance, and the extensions reported by `composer check-platform-reqs`, plus `pdo_pgsql` for this application and the supplied image's `intl`/`pcntl` development tooling support. PHP 8.3 cannot install the locked Symfony 8.1 dependencies even though the root Composer constraint allows it.
- Node/npm and frontend builds are unnecessary for this JSON API.

## Installation and database setup

```bash
git clone https://github.com/Assemessam/ecommerce-order-api-.git ecommerce-order-api
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

Compose starts its healthy PostgreSQL and dedicated Redis dependencies automatically. The API is available at **http://localhost:8091** by default, with the forwarded host port bound only to localhost (`127.0.0.1:${APP_PORT:-8091}:8000`):

```bash
curl -H 'Accept: application/json' http://localhost:8091/api/health
# {"data":{"status":"ok"}}
```

The container still listens on `0.0.0.0:8000` for Docker-internal communication, and the Postman environment continues to use `http://localhost:8091`. For explicit access from another machine, change only the API port mapping in `compose.yaml` to `0.0.0.0:${APP_PORT:-8091}:8000` (or a specific host interface), then recreate the API with `docker compose up -d api`. Set `APP_URL` and client URLs to the reachable address and restrict access with your host firewall; this exposes a development server and should be limited to a trusted development network.

On Linux the bind-mounted files must be writable by the container user (default UID/GID 1000). Override the Compose user for one-off setup commands with `--user "$(id -u):$(id -g)"` if your host IDs differ. No production web server is included: `artisan serve` is a development runtime.

The additive RBAC migrations create Spatie's five standard tables, bootstrap three internal roles and seven permissions, and backfill only existing `is_admin=true` users as Administrator. They create no user accounts. The default `DatabaseSeeder` runs only `AuthorizationSeeder`, which can idempotently restore the canonical role/permission definitions without granting users roles. Ordinary customers have no internal role.

For an interconnected local demonstration, configure a private `DEMO_SEED_PASSWORD` in `.env`, then run `docker compose exec -T api php artisan db:seed --class=DemoDatabaseSeeder --no-interaction`. This opt-in local/testing PostgreSQL dataset includes eight accounts, sixteen products, nine promotions, five carts, and seven genuine purchases including a cancellation. The [seeding guide](database/seeders/README.md) documents the complete table inventory, credentials, fixtures, repeatability, read-only inspection commands, and Postman usage.

### Environment

| Setting | Local behavior |
|---|---|
| `APP_KEY` | Generated above; never commit it |
| `APP_ENV`, `APP_DEBUG` | Local defaults; use production / false outside development |
| `DEMO_SEED_PASSWORD` | Private local-only password for new opt-in demo accounts; no default; 12–72 bytes without NUL |
| `APP_URL`, `APP_PORT` | Default host URL and port 8091; change both when changing the port |
| `CATALOGUE_CURRENCY` | One uppercase three-letter currency label; defaults to USD; no currency conversion |
| `DB_*` | Compose explicitly supplies pgsql / postgres:5432 / ecommerce_order_api / ecommerce / local-only password `secret` |
| `DB_FORWARD_PORT` | Optional host database access at `127.0.0.1:15433` by default; does not change the container's `DB_PORT=5432` |
| `CACHE_STORE` | General application cache; HTTP throttling uses its dedicated Redis connection |
| `CATALOGUE_CACHE_ENABLED`, `CATALOGUE_CACHE_STORE` | Public listing cache enabled; dedicated `catalogue` Redis store |
| `CATALOGUE_CACHE_TTL` | 45-second freshness budget; configurable, clamped to 1–300 seconds; slow-read time is deducted |
| `CATALOGUE_CACHE_NAMESPACE`, `CATALOGUE_CACHE_PREFIX`, `REDIS_PREFIX` | Separate application/environment namespaces; configure unique values when sharing Redis |
| `REDIS_HOST`, `REDIS_PORT`, `CATALOGUE_REDIS_DB` | Compose `redis:6379`, catalogue DB 2; tests force DB 3 |
| `REDIS_USERNAME`, `REDIS_PASSWORD`, `CATALOGUE_REDIS_URL` | Optional private credentials/authenticated TLS URL; keep secrets outside Git |
| `CATALOGUE_REDIS_TIMEOUT` | 0.2-second connection/read timeouts; no catalogue client retries |
| `ORDER_EVENTS_QUEUE_CONNECTION`, `ORDER_EVENTS_QUEUE` | Dedicated `order-redis` queue connection and `order-events-local` queue in Compose |
| `ORDER_EVENTS_REDIS_DB`, `ORDER_EVENTS_REDIS_URL` | Separate queue DB 4 (test DB 5); optional private queue Redis URL |
| `RATE_LIMIT_REDIS_DB`, `RATE_LIMIT_REDIS_URL`, `RATE_LIMIT_REDIS_PREFIX` | Dedicated limiter Redis DB 6 (test DB 7), optional private URL and per-environment/deployment namespace |
| `RATE_LIMIT_*_PER_MINUTE`, `RATE_LIMIT_REGISTER_PER_HOUR` | Endpoint budgets in `.env.example` / `config/rate-limits.php`; minimum 1, no disable switch |
| `TRUSTED_PROXIES` | Empty by default; explicit comma-separated reverse-proxy IPs/CIDRs, with X-Forwarded-For trusted only from those peers |
| `ORDER_EVENTS_LEASE_SECONDS`, `ORDER_EVENTS_DISPATCH_RETRY_SECONDS`, `ORDER_EVENTS_BATCH_SIZE` | Recoverable 300-second claims, 15-second broker retries and bounded 100-event relay batches |

Compose supplies the internal database/Redis hosts to the API, worker, and scheduler. The API uses `artisan serve --no-reload` so its child server retains these environment overrides, even when `.env` contains native host addresses. Restart the API with `docker compose restart api` after changing `.env`; clear cached configuration first if present. Native execution requires reachable PostgreSQL/Redis hosts and your own database credentials. The supplied tests intentionally require the isolated Compose host and dedicated test database; native test execution without an equivalent setup is unsupported.

PostgreSQL publishes **only `127.0.0.1:${DB_FORWARD_PORT:-15433}`**, for optional host database clients. API, worker, scheduler, and tests still connect to the project service at `postgres:5432`; they do not use that forwarded port or `postgres-local`. Choose another unused `DB_FORWARD_PORT` if 15433 is occupied. The private Compose network and `postgres_data` volume belong to this project. The PostgreSQL image creates `ecommerce_order_api`, and the initialization SQL creates the separate `ecommerce_order_api_test` database on a fresh volume. Existing volumes without the test database require explicit provisioning of that test database; initialization scripts do not rerun on an existing volume. Tests refuse unexpected targets before refreshing tables.

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

Do not reset the development database or modify `postgres-local` or unrelated Docker projects. A second checkout must use a distinct Compose project name, API port (`APP_PORT`/`APP_URL`), and `DB_FORWARD_PORT` to avoid sharing volumes or conflicting with published ports. The optional sample seeders insert six products and seven promotions without resetting prices, stock, customers, or existing promotions. Register your own customer through the API or explicitly opt in to the separate `DemoDatabaseSeeder`; the default `DatabaseSeeder` creates no accounts.

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

## API rate limiting (Bonus 7D)

Named Laravel limiters protect every defined `/api` endpoint before business operations. Registration permits 5/hour/IP. Login retains 30/minute/IP and 5/minute/account+IP, plus 20/minute/account across IPs. Catalogue, cart/order/profile reads, health and admin reads allow 120/minute in separate categories; cart writes 60; promotion/admin/logout writes 30; checkout/cancellation 20. Public identities use validated canonical IPs; every authenticated policy uses stable `user:<id>` identity. Budgets are shared across tokens and routes within each category. Adding, removing, or combining roles does not reset or multiply allowance. No global API limiter is nested around these policies.

HTTP 429 uses the existing `TOO_MANY_REQUESTS` JSON envelope and request ID, with native `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` and rejection reset headers. Replays and invalid requests consume allowance. A throttled checkout has no purchase effects; retrying its idempotency key after the window expires preserves the original checkout contract. Missing authentication returns 401 before throttling; authenticated authorization failures normally return 403, and repeated attempts can return 429 before authorization.

A dedicated Redis connection uses DB 6 / its own key prefix, 0.2-second connect/read timeouts and no reconnect retries. The small native middleware adapter honors Laravel's atomic Lua acquisition result to prevent the installed framework's check-then-hit race. Limiter connection failures return generic 503 before services run. The development Redis eviction policy can reset allowances; use a private non-evicting limiter Redis for strict production enforcement. Queue workers and the outbox relay have no HTTP limiter. Default proxy trust is empty; only configure actual controlled reverse-proxy addresses. Keep `/up` for internal unthrottled liveness checks. See [the report](docs/17-api-rate-limiting.md) for all budgets, configuration, trade-offs and actual results.

## Tests and quality checks

Local regression verification for the CI/security update on October 8, 2026 passed **1,218 tests and 7,210 assertions** with `docker compose exec -T api php artisan test --compact`. Separate sequential reruns of the seven standalone concurrency suites below passed **40 tests and 806 assertions**; those tests are also included in the full suite. These are new local runs, separate from the independent review of the published commit and from GitHub-hosted CI.

Run database suites **sequentially**. The contention tests use committed fixtures and migration teardown, so concurrent suite invocations against the same test database are unsupported. Do not use `--parallel` with the guarded single-database configuration.

```bash
docker compose exec -T api php artisan test --compact
# Native HTTP limiter, login and independent-process Redis admission checks:
docker compose exec -T api php artisan test --compact \
  tests/Feature/Http/Middleware/ThrottleApiRequestsTest.php \
  tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php \
  tests/Feature/Http/Controllers/Api/AuthControllerTest.php
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
  tests/Feature/Services/Admin/AdminConcurrencyTest.php \
  tests/Feature/Services/Order/OrderOutboxConcurrencyTest.php \
  tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
git diff --check
```

HTTP/service/repository/constraint tests use real PostgreSQL; the calculator is tested independently with exact expected integers. Fault injection covers rollback and normally unreachable corrupt/missing states. Real contention tests use separate PHP processes and PostgreSQL connections, an independent observer, and two observed lock waiters before barrier release. They exercise authenticated HTTP kernel requests; they are not a network load benchmark. Injected SQLSTATE deadlock tests establish retry behavior, not observed deadlock cycles. No static analyzer is installed; see the audit's Larastan assessment.

The complete suite requires the project Redis service. Rate limiting stays enabled with a new UUID prefix in Redis DB 7 for each test; HTTP child processes share that prefix and cleanup deletes only its keys. Existing tests disable catalogue caching by default; caching tests explicitly enable it with a unique namespace in Redis DB 3. Cleanup deletes only that namespace's keys and never calls FLUSHDB/FLUSHALL. The benchmark uses 25 uncached and 25 warm samples per listing variant, reports actual timings and product query counts, and removes its PostgreSQL/Redis fixtures. Local measurements are not production capacity estimates. Redis-enabled independent-process concurrency verification is described in [the bonus report](docs/15-redis-caching.md).

RBAC coverage includes exact role/permission mappings, legacy backfill, registration/mass-assignment protection, current permissions on existing tokens and reused users, inventory authorization, all-staff customer ownership, local provisioning, and role-independent rate budgets. Spatie metadata uses the isolated in-memory `array` cache store; it never shares the catalogue, queue, or limiter Redis connection. See [the historical refactor verification record](docs/19-product-service-rbac-refactor.md).

### GitHub Actions CI

The [PostgreSQL and Redis regression workflow](.github/workflows/ci.yml) runs on pushes to `main`, pull requests targeting `main`, and manual workflow dispatch. It builds the committed Dockerfile's PHP 8.5/Composer runtime and uses the existing Compose PostgreSQL 17 and Redis 7.4 services on an ephemeral GitHub runner. Each run has its own Compose project, fresh database volume, runner UID/GID, testing configuration, and generated application key. Health checks and explicit database/Redis readiness checks run before verification; the initialization SQL provisions the guarded `ecommerce_order_api_test` database.

The workflow verifies platform requirements, strict Composer validation, locked dependency security, and Pint formatting. Its OpenAPI gate migrates only the isolated test database, exports the specification with strict inference checks, runs focused documentation/contract tests, and fails if the committed export is stale. It then runs the complete regression suite **sequentially**, including all seven concurrency suites, against real PostgreSQL and Redis. Existing test safeguards and isolated Redis DBs 3 (catalogue), 5 (order events), and 7 (rate limiting) remain enabled. The exact CI test command is:

```bash
docker compose run --rm --no-deps -T \
  -e DB_DATABASE=ecommerce_order_api_test api \
  php artisan test --compact --ci --log-junit=storage/logs/junit.xml
```

CI uses `contents: read` permissions and commit-pinned checkout/artifact actions. It uses local runner services, local-only service credentials, and an ephemeral application key; retains JUnit, test and quality-check logs plus failure diagnostics for seven days; writes a job summary; and cleans up its own Compose resources. The job has a 30-minute timeout and performs no deployment.

A local rehearsal on October 8, 2026 executed the workflow's setup, quality checks, and exact test command from a fresh checkout with a separate Compose project and fresh database volume. It passed **1,218 tests and 7,210 assertions**; its JUnit report, diagnostics, summary, and isolated cleanup were also verified. Actionlint, YAML parsing, and shell syntax checks passed.

The [GitHub-hosted regression run on `main`](https://github.com/Assemessam/ecommerce-order-api-/actions/runs/37792071884) passed for `bafe9e1e1ea3bab2b837e4e1138c2626926d6a10` after the CI pull request was merged on October 8, 2026. Hosted verification of the later OpenAPI integration remains pending until this branch is reviewed and published. Inspect future jobs, summaries, and artifacts in [GitHub Actions](https://github.com/Assemessam/ecommerce-order-api-/actions); the workflow also supports manual **Run workflow**.

## OpenAPI and interactive documentation

Scramble **0.13.47** generates the **OpenAPI 3.1** contract and the interactive Stoplight Elements interface from the existing Laravel API. It covers all 25 operations, their bearer authentication, JSON schemas, examples, success statuses, and relevant errors.

- Interactive documentation: **http://localhost:8091/docs/api**.
- Generated JSON: **http://localhost:8091/docs/api.json**.
- Version-controlled export: [docs/openapi.json](docs/openapi.json).
- Coverage comparison, schema details, and usage: [OpenAPI guide](docs/20-openapi.md).

For an existing configured installation, start the application with `docker compose up -d api`. For a new installation, follow [the installation commands above](#installation-and-database-setup), preserving any existing `.env` and application key. The documented URLs use the default `APP_PORT`; use your configured port when it differs. The specification's relative `/api` server uses the documentation page's actual origin and port.

Register or log in through **Authentication**, copy `data.token`, and enter **only the token**, without the `Bearer ` prefix, into the bearer authentication input for an authenticated operation. The interface builds `Authorization: Bearer <token>` for **Try It Out**. Customer endpoints use that user's cart and orders. Administrative endpoints additionally require their actual Spatie permissions; grant an existing local staff account a role with `docker compose exec -T api php artisan roles:grant your-staff@example.test administrator --no-interaction`, then log in as that account. `product_manager` and `promotion_manager` grant their corresponding administrative permissions. There is no role-assignment REST endpoint.

Both documentation routes are accessible **only when `APP_ENV=local`**; testing, staging, and production receive 403, even with a bearer token or `APP_DEBUG=true`. These restrictions do not affect the committed public contract. Stoplight's browser assets load from its CDN, so interactive rendering requires network access.

Regenerate after API changes, then verify the contract against the guarded PostgreSQL/Redis test environment:

```bash
docker compose exec -T api php artisan scramble:export --path=docs/openapi.json --fail-on-unknown --no-interaction
docker compose exec -T api php artisan test --compact tests/Feature/OpenApiDocumentationTest.php
```

The export is generated rather than maintained independently. OpenAPI describes the contract; the existing Postman collection executes complete workflows and negative regression scenarios. Keep bearer tokens and private credentials out of both exported artifacts.

## API documentation and Postman

[docs/07-api-contracts.md](docs/07-api-contracts.md) contains payloads, resources, pagination, validation, and domain error codes. Import [the Postman collection](postman/Ecommerce_Order_Promotion_API.postman_collection.json) and [the local Docker environment](postman/Ecommerce_Local_Docker.postman_environment.json), then select the environment. Its default `base_url` is `http://localhost:8091`, without `/api`. [The Postman usage guide](postman/README.md) provides Docker startup, local administrator provisioning, variable lifecycle, negative-test prerequisites, and the complete 25-operation route-to-request coverage matrix.

Provision a dedicated local administrator once, then run only **Complete E-Commerce Workflow** in the Collection Runner with one iteration. It logs in the administrator, generates a fresh customer, creates its own stocked product and eligible promotion, exercises the cart, checks out and replays the key, cancels and retries, verifies stock restoration, and logs out. Sample seeders are unnecessary. Individual folders support manual testing; negative scenarios remain separate. Registration has a default limit of five attempts per IP per hour. Keep local passwords and captured `customer_token` / `admin_token` private, and remove runtime secrets before exporting or sharing. Manual checkout preserves `idempotency_key` for replay; clear it before a new purchase. Coverage and static validation are distinct from the actual execution results reported at completion.

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

Registration requires name, email, password, and password_confirmation. Emails normalize to lowercase. Passwords require 12+ characters, mixed case, a digit and symbol; bcrypt's 72-byte limit and null-byte rejection apply. Login accepts existing credentials without imposing the registration strength rules again. Optional device_name labels the token. Sanctum hashes tokens at rest, authenticates bearer tokens only, and logout revokes the current token without affecting other devices. Registration/login are rate limited; tokens retain the documented no-expiry and wildcard-ability policy. Admin access requires current Spatie permissions; existing tokens reflect role grants and revocations without reissuance. Registration, mass assignment, and normal customer resources cannot assign or expose internal roles/permissions.

Cart IDs are inferred from the token. Item URLs identify cart lines, not products. POST adds to a quantity; PATCH replaces it. Product IDs and quantities must be actual positive JSON integers. Extra identity/price/total fields cannot alter the purchase. Missing/foreign resources return indistinguishable 404 errors. An absent cart reads as an empty estimate without creating a record. Cart edits never reserve or change inventory.

Successes use `data`, plus `links` and `meta` for paginated collections; 204 responses have no body. Errors always use JSON, including without an Accept header:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","details":{"fields":{"quantity":["The quantity field is required."]}},"request_id":"<uuid>"}}
```

Authentication is 401, ownership/missing resources 404, validation 422, business/stock/state conflicts 409, and API throttling 429 with Retry-After, or limiter unavailability 503 before business operations. Unexpected failures are generic 500 without SQL or traces even in local debug mode. API responses include X-Request-ID and Cache-Control: no-store, private. Resource fields are explicitly allow-listed.

## Service + Repository architecture

```text
Controller → Service → Repository interface → Eloquent repository → Model → PostgreSQL
```

Form Requests validate input; controllers select HTTP responses; services own business rules and transaction boundaries; repositories encapsulate queries, row locks, and writes. Repository contracts are bound in AppServiceProvider. Checkout and cancellation coordinate several repositories inside one service transaction; repositories do not independently commit workflows. Pricing/eligibility calculations are shared by estimates and checkout. Native Sanctum issuance/revocation is a documented framework persistence exception inside AuthService. Detail relationships are eager-loaded; order summaries do not query live catalogue data.

Authentication and admin product/promotion controllers convert validated multi-field inputs into the seven immutable application DTOs listed in [app/DTOs/README.md](app/DTOs/README.md). Services retain authorization, business validation, and transaction ownership; repository interfaces retain their existing persistence contracts.

Architecture decisions are in [docs/05-architecture.md](docs/05-architecture.md), schema/constraints in [docs/06-database-design.md](docs/06-database-design.md), and detailed transaction discussions in [checkout](docs/11-checkout.md) and [order management](docs/12-order-management.md). Earlier milestone verification sections are historical; [the structural audit's superseding status](docs/18-structural-integrity-audit.md#superseding-release-status--october-8-2026) records the later release verification.

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

## Product and promotion administration with Spatie RBAC

Apply additive migrations with `docker compose exec -T api php artisan migrate --no-interaction`. Spatie Laravel Permission provides three internal roles: `product_manager`, `promotion_manager`, and `administrator`. Customers have no internal role; users may hold both manager roles to receive their combined permissions. Administrator receives all seven permissions through Spatie assignment. There is no universal Administrator policy bypass. All staff remain subject to cart/order/checkout/cancellation ownership.

| Permission | Product Manager | Promotion Manager | Administrator |
|---|---|---|---|
| `products.view-admin` | Yes | No | Yes |
| `products.create` | Yes | No | Yes |
| `products.update` | Yes | No | Yes |
| `inventory.adjust` | Yes | No | Yes |
| `promotions.view-admin` | No | Yes | Yes |
| `promotions.create` | No | Yes | Yes |
| `promotions.update` | No | Yes | Yes |

Provision roles **only in local development**: register your own account with a private password, then run a command for that existing email while APP_ENV=local (replace the example email with your registered local email):

```bash
docker compose exec -T api php artisan roles:grant developer@example.test product_manager --no-interaction
docker compose exec -T api php artisan roles:grant developer@example.test promotion_manager --no-interaction
docker compose exec -T api php artisan roles:revoke developer@example.test product_manager --no-interaction
# Compatibility alias: grants the administrator role.
docker compose exec -T api php artisan admin:grant developer@example.test --no-interaction
```

Grant/revoke commands accept only canonical roles and existing users, are idempotent, and create/change no password or token. They refuse all other environments. `admin:grant` remains an alias for granting Administrator; revoke it with `roles:revoke ... administrator`. Postman's **Authentication** folder registers a dedicated local admin setup account and captures `admin_token` using empty-by-default `admin_email` / `admin_password` variables; grant its role with the CLI, then send **Login admin**. The admin product/promotion folders and complete workflow require Administrator or both manager roles. No role-management HTTP endpoint or production provisioning workflow is added.

The retained `users.is_admin` column is hidden audit history. Only the initial migration reads it to backfill existing administrators; it no longer authorizes, controls limiter identity, or mirrors later assignments. A true flag without a permission grants no access. Spatie's permission namespace is `web`, matching the existing User provider, while the API continues to require Sanctum bearer tokens. Permission metadata uses `array` cache with `spatie.permission.cache`; supported package mutation APIs invalidate it, and authorization clears loaded User role/permission relationships before checking current assignments.

Product POST accepts name, SKU, price_minor and optional description, stock_quantity and status. PATCH requires `products.update`; supplying **stock_adjustment** additionally requires `inventory.adjust`, before validation and again for direct service calls. The signed, nonzero delta applies to locked current stock; absolute stock_quantity on PATCH is rejected. Prices and inventory use actual JSON integers with signed-bigint bounds. Example PATCH: `{"price_minor":2099,"stock_adjustment":-2,"status":"inactive"}`. A negative result or overflow returns 409 and rolls back all edits. Separate stock-adjustment requests are additive and not idempotent: don't blindly retry an uncertain response. Inactive products remain available to permitted admin reads but hidden publicly; historical order items remain unchanged.

Promotion POST/PATCH use the existing percentage basis points/fixed minor-unit value, caps, validity, usage limits and is_active flag. Example PATCH: `{"type":"fixed","value":500,"is_active":false}`. Partial changes are checked against retained fields under the promotion lock. Supplied non-null limits below consumed global/largest individual customer usage return 409; equality is allowed and exhausts eligibility, null removes the limit. Edits affect future checkout; order snapshots and redemptions are preserved. Retirement uses deactivation; no hard-delete routes exist.

Both public and administrative product controllers use the unified `ProductService`; `ProductAdministrationService` has been removed. Explicit public/admin method names preserve public active visibility and authoritative inactive admin reads. `ProductCatalogueCache` remains separate, and ProductService schedules mutation invalidation after commit. Transactions lock only the affected Product or Promotion row, with bounded retries. Checkout/cancellation retain their original lock order, calculations and ledger rules. Independent PHP/PostgreSQL contention tests use domain manager actors and preserve both queued orders for stock changes, cancellation restoration, usage-limit reductions, and discount changes. Current architecture and historical refactor evidence are in [docs/19-product-service-rbac-refactor.md](docs/19-product-service-rbac-refactor.md); [Bonus 7A](docs/14-admin-management.md) retains its original historical evidence.

## Trade-offs and known limitations

- No payment processing/refunds, shipping/tax integrations, admin order management, frontend, email verification/recovery, or additional lifecycle states.
- One currency, one cart/customer, one promotion/order, no inventory reservation, and bounded signed-bigint amounts. JavaScript clients must use lossless JSON handling for integers above 2^53−1.
- Popular product/promotion rows serialize short transactions. Substring search has no trigram index; ledger counts grow with history. No production-scale benchmark or universal deadlock-freedom claim is made.
- Per-customer limits have sequential usage and concurrent admin limit-reduction coverage. A separate checkout-versus-checkout scenario with no global limit, sufficient stock, distinct keys, and overlapping exhausted/eligible customers remains a coverage follow-up; same-customer requests may instead serialize to an empty cart or replay. Review found no correctness defect.
- Snapshot immutability, redemption identity consistency, and inventory restoration semantics assume supported service writers; privileged SQL can bypass them. Legacy redemption rows may have NULL order_id. Retention/account deletion policy is undecided; purchase-history foreign keys restrict deletion.
- The cancellation migration cannot downgrade while cancelled history exists: the earlier placed-only schema cannot represent it. Use forward fixes for retained data.
- Dropping the Spatie schema loses role/permission assignments. Rolling back and reapplying the bootstrap can regrant revoked Administrator roles to retained legacy `is_admin=true` users; returning to old boolean-authorizing code can also revive stale flags. Use forward fixes. Provisioning commands are local-only, so production operator provisioning remains a separate workflow.
- The initial stable `user:<id>` limiter rollout does not reuse old authenticated counters. Coordinate the one-minute transition; subsequent role changes preserve allowance.
- Category-based throttles and indefinite customer tokens are explicit assessment policies. Limiter outages fail closed with 503; Redis eviction/restarts reset allowances and fixed windows permit boundary bursts. Production infrastructure, TLS, managed secrets, backups, and broader abuse controls require a separate operational review.
- Demo seeders preserve existing edits and promotion validity windows rather than resetting the database; see the [rerun rules](database/seeders/README.md#reruns-and-preservation) before repairing manually changed fixtures.

Historical development note: the ProductService/RBAC refactor was developed on `feature/product-service-rbac-refactor` from `bb6f50f` for the earlier `release/ecommerce-assessment-final` integration. The project is now published on `main`. The refactor's verification and remaining limits are preserved in [docs/19-product-service-rbac-refactor.md](docs/19-product-service-rbac-refactor.md); the earlier submission rehearsal remains in [docs/18-final-submission-review.md](docs/18-final-submission-review.md).
