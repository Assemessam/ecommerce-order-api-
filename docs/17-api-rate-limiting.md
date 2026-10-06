# Bonus Milestone 7D — API Rate Limiting

## Git verification and Bonus 7C commit

The starting branch was `feature/order-events-queues-7c`; HEAD was exactly the Redis parent `c11fee5fbe1a57ac99e627c63c518e82d5582897`. The staged inventory was empty, and all 41 modified/new paths matched the order events/outbox milestone. Review covered the actual events, PostgreSQL transactional outbox, queue job/listener, dispatch ownership, lease recovery, Redis worker configuration, duplicate processing, retries/failure hooks and the existing checkout/cancellation transaction paths. No unrelated changes, populated private environment files, generated artifacts or private secrets were staged. Supplied local Compose test credentials are development fixtures.

Executed before commit on October 6, 2026:

| Check | Actual result |
| --- | --- |
| Complete PostgreSQL baseline | **821 passed, 4,948 assertions**, 44.70 s |
| Focused event/job/outbox/model/checkout/cancellation set | **57 passed, 494 assertions**, 7.23 s |
| Five existing standalone PostgreSQL contention suites | **34 passed, 631 assertions**, 12.78 s |
| Standalone native Redis jobs and outbox concurrency | **13 passed, 131 assertions**, 5.29 s (11 jobs + 2 outbox cases) |
| Event/schedule discovery | Both immutable events/listener discovered; relay scheduled every ten seconds |
| Pint, Composer validation/audit, Compose validation, whitespace | Passed; no dependency advisories |

Bonus 7C was committed as **`49732ad851d8163e47b91be2979a0ffda8cc974d`**, message **`feat: add transactional outbox and queued order events`**, on `feature/order-events-queues-7c`. The reviewed staged diff contained **41 files, 1,709 insertions, 25 deletions**. Its complete created/modified file inventory is retained in [16-order-events-queues.md](16-order-events-queues.md#git-operations-and-final-file-inventory); that earlier report's uncommitted wording records the state when 7C was originally delivered. The actual commit is authoritative.

After commit the working tree was clean. `feature/api-rate-limiting-7d` was created directly from that completed commit, checked out, and verified clean with an identical tree. The verified 821-test baseline therefore covers the exact 7D starting tree. Earlier milestones, Redis catalogue caching and the transactional outbox are retained. No reset, stash, merge, rebase or push was used. 7D is intentionally left uncommitted; the completed 7C branch remains at its commit.

## Architecture and installed framework behavior

Installed versions were verified with Composer/Boost: PHP 8.5, Laravel **13.34.0**, Sanctum **4.3.3**, Pest **4.7.8**, PHPUnit **12.5.33**, Pint **1.32.1**. Version-scoped Boost documentation and installed source were consulted before implementation. `.ai/rules` is absent; the Laravel and testing skills and sibling conventions were followed. No dependencies changed.

`AppServiceProvider::boot()` calls `App\Http\RateLimiting\ApiRateLimiters::register()`. This infrastructure-only class defines native `RateLimiter::for()` policies and `Limit::perMinute()` / `Limit::perHour()` identities from `config/rate-limits.php`. Each route has one named `throttle:*` policy. There is no additional global/nested API limiter. Login deliberately returns three complementary limits within its single named policy.

`bootstrap/app.php` enables Redis throttling and maps the throttle alias to `ThrottleApiRequests`, a small subclass of Laravel's `ThrottleRequestsWithRedis`. It uses the dedicated connection and Laravel's own `DurationLimiter::acquire()` and Lua script. Named policy resolution, key hashing, denial exceptions and response headers are inherited. It creates no custom Redis algorithm, Lua script, Service/Repository framework, application lock or database counter.

**Why the adapter is necessary:** Laravel 13.34.0's native middleware checks `tooManyAttempts()` separately, then calls `acquire()` through `hit()` without honoring the rejected reservation result. An executed barrier test using eight real independent processes/connections at a three-request limit admitted **all eight** with the unmodified native behavior. The adapter reserves once during the admission check, honors the atomic result and makes the following hit a no-op. The same forced barrier now admits exactly three and rejects five. Review this narrow compatibility adaptation after framework upgrades; never replace it with the unmodified middleware without rerunning the contention test. All configured policies count requests before execution; do not introduce response-based `Limit::after()` policies into this adapter.

The existing Controller → Service → Repository Interface → Eloquent Repository → PostgreSQL architecture is unchanged. CheckoutService, OrderService, the catalogue cache and the entire queue/outbox pipeline have no 7D implementation edits.

## Policies, paths and default thresholds

Related routes in each row share one allowance. Every `GET` also includes its native HEAD route. All values are positive configurable integers, clamped to a minimum of one; zero does not disable security middleware.

| Named policy | Protected endpoints | Default | Identity | Environment variable |
| --- | --- | --- | --- | --- |
| `auth-register` | POST `/api/auth/register` | 5/hour | IP | `RATE_LIMIT_REGISTER_PER_HOUR` |
| `auth-login` | POST `/api/auth/login` (also admin login) | 30/minute/IP; 5/minute/account+IP; 20/minute/account | Three distinct identities | `RATE_LIMIT_LOGIN_IP_PER_MINUTE`, `RATE_LIMIT_LOGIN_ACCOUNT_IP_PER_MINUTE`, `RATE_LIMIT_LOGIN_ACCOUNT_PER_MINUTE` |
| `catalogue-read` | GET `/api/products`, `/api/products/{id}` | 120/minute | IP | `RATE_LIMIT_CATALOGUE_PER_MINUTE` |
| `health-read` | GET `/api/health` | 120/minute | IP | `RATE_LIMIT_HEALTH_PER_MINUTE` |
| `cart-read` | GET `/api/cart` | 120/minute | Stored user | `RATE_LIMIT_CART_READ_PER_MINUTE` |
| `cart-mutation` | POST `/api/cart/items`; PATCH/DELETE `/api/cart/items/{id}` | 60/minute | Stored user | `RATE_LIMIT_CART_MUTATION_PER_MINUTE` |
| `promotion-mutation` | POST/DELETE `/api/cart/promotion` | 30/minute | Stored user | `RATE_LIMIT_PROMOTION_PER_MINUTE` |
| `checkout` | POST `/api/checkout` | 20/minute | Stored user | `RATE_LIMIT_CHECKOUT_PER_MINUTE` |
| `order-read` | GET `/api/orders`, `/api/orders/{id}` | 120/minute | Stored user | `RATE_LIMIT_ORDER_READ_PER_MINUTE` |
| `order-cancel` | POST `/api/orders/{id}/cancel` | 20/minute | Stored user | `RATE_LIMIT_ORDER_CANCEL_PER_MINUTE` |
| `admin-read` | GET `/api/admin/products`, `/api/admin/products/{id}`, `/api/admin/promotions`, `/api/admin/promotions/{id}` | 120/minute | Stored admin | `RATE_LIMIT_ADMIN_READ_PER_MINUTE` |
| `admin-mutation` | POST `/api/admin/products`, `/api/admin/promotions`; PATCH either resource `/{id}` | 30/minute | Stored admin | `RATE_LIMIT_ADMIN_MUTATION_PER_MINUTE` |
| `account-read` | GET `/api/auth/me` | 120/minute | Stored user | `RATE_LIMIT_ACCOUNT_READ_PER_MINUTE` |
| `account-mutation` | POST `/api/auth/logout` | 30/minute | Stored user | `RATE_LIMIT_ACCOUNT_MUTATION_PER_MINUTE` |

Registration's existing five-per-minute rule was deliberately tightened to five per hour. Login retains its existing IP and account/IP budgets, with a short account-wide budget added. Read-heavy defaults and the 20/minute checkout/cancellation defaults admit all existing two-worker contention scenarios without changing them or exempting test middleware. Profile/logout and API health were also protected to cover all defined API endpoints. Laravel's `/up` stays outside the API group for internal liveness probes.

## Identity, privacy and trusted proxies

Public policies always use `Request::ip()`, followed by IP validation and hashing of canonical `inet_pton()` bytes. Equivalent IPv6 textual representations share allowance. An invalid peer IP returns generic 400. Body identities, login tokens and arbitrary forwarding headers cannot select a different public quota.

Authenticated keys use the current Sanctum user's database ID plus `customer:` or `admin:` according to its stored `is_admin` flag. Different users, roles and policy names have different identities; multiple tokens/IPs for one user share its category allowance. Public catalogue limits continue to use IP even when an Authorization header is present. Request `user_id`, role and administrator flags are never trusted.

Login normalizes email exactly as authentication does (trim + lowercase). It derives a stable HMAC-SHA-256 using the app key, with distinct `ip:`, `account-ip:` and `account:` identities. Raw emails/passwords/tokens are absent from Redis keys/values. Native middleware further hashes policy name + identity; the Redis value holds only `start`, `end`, `count`. App-key rotation changes account identities, so account budgets reset during that rotation.

Laravel's global `TrustProxies` middleware handles forwarding. `config/trustedproxy.php` supplies an explicit **empty array by default**, which also prevents the framework's provider-hostname inference from trusting an arbitrary peer. `TRUSTED_PROXIES` accepts only the intended deployment's controlled reverse-proxy IPs/CIDRs, comma-separated; never use a wildcard or a network containing untrusted clients. Only `X-Forwarded-For` is trusted for limiter identity. The edge must replace/sanitize incoming forwarding headers and prevent bypass access to the origin. Unit/HTTP evidence includes spoofed X-Forwarded-For/Forwarded, provider-shaped Host headers, a known allowed proxy and actual separate peer IPs.

## Redis configuration and isolation

| Setting | Default / behavior |
| --- | --- |
| Connection | `database.redis.rate-limits`; selected by `rate-limits.connection` |
| `RATE_LIMIT_REDIS_DB` | **6** locally; PHPUnit/HTTP worker guards require **7** |
| `RATE_LIMIT_REDIS_PREFIX` | `ecommerce:rate-limits:APP_ENV:v1:`; provide a deployment-specific prefix when multiple deployments share Redis |
| `RATE_LIMIT_REDIS_URL` | Optional private authenticated/TLS Redis URL; forced empty in tests |
| Host/authentication | Existing `REDIS_HOST`/`REDIS_PORT`/`REDIS_USERNAME`/`REDIS_PASSWORD`; Compose host `redis` |
| Client | Existing PhpRedis; 0.2-second connect/read timeout, zero reconnect retries |
| Other stores | Catalogue DB 2 / test 3; order queue DB 4 / test 5; existing default/cache DBs untouched |

The general cache store stays unchanged. The native Redis throttle accesses its dedicated connection directly; adding `cache.limiter` alone would not control this middleware's Redis connection. No catalogue invalidation, cache clearing, queue clearing or global Redis flush is performed by the HTTP limiter. Compose has no published Redis port and needs no service/image/volume changes for 7D. No unrelated container was altered.

Environment values are read only in configuration files. For existing local setups the new defaults work without editing a private `.env`; copy the relevant `.env.example` variables only if overriding them. If configuration is cached, rebuild it after changing settings; do not clear data to reload limiter policies:

```bash
docker compose exec -T api php artisan config:clear --no-interaction
docker compose exec -T api php artisan route:list --path=api --except-vendor -vv
```

Use the project's normal production configuration cache/reload procedure when shipping later. No configuration cache, deployment or worker restart was performed as part of 7D.

## Middleware, authentication and authorization

Observed resolved route order is global proxy/request context → `auth:sanctum` where required → named throttle → bindings → route policy → request validation/controller → existing business services. Laravel's middleware priority recognizes the adapter's native parent, so authentication resolves before the identity callback. There is no body-derived guest fallback for user policies.

Guests/invalid tokens receive **401 before a user limiter runs**, even if they submit an administrator flag. Normal forbidden authenticated access remains **403**. Authenticated attempts consume allowance before route authorization, so repeated forbidden requests can receive **429** instead; their quotas are scoped to that requesting user/role, not a legitimate admin. This precedence deliberately bounds repeated forbidden work and never grants privileges. Successful/invalid/failed requests count; quotas are not released on authorization, validation or business failure.

Account-wide login throttling reduces distributed-IP brute force while its one-minute window bounds an ordinary lockout. It does not permanently lock the account. It also counts correct credentials and nonexistent accounts, avoiding a credential-dependent admission path. An attacker can still deny a targeted account's login during continuing abuse; thresholds balance this risk with brute-force protection. Shared-NAT users also share public/login-IP allowance. MFA, challenge flows, password recovery and upstream abuse detection remain outside this milestone.

## HTTP 429 and Retry-After

The existing API error convention is preserved, including its uppercase code:

```json
{
  "error": {
    "code": "TOO_MANY_REQUESTS",
    "message": "Too many requests. Please try again later.",
    "request_id": "generated-uuid"
  }
}
```

Rejections include HTTP **429**, JSON content type, generated `X-Request-ID` matching the body, `Cache-Control: no-store, private`, native `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0`, integer `Retry-After` seconds and `X-RateLimit-Reset` epoch seconds. HEAD preserves status/headers and has no body. JSON is returned without requiring Accept for other requests. Responses that pass the limiter carry the native limit/remaining headers; early 401 and unmatched routes do not.

Native fixed windows start at first acquisition. Rejections increment the attempted-request count but do not extend the window; remaining is clamped to zero. Each hash expires at twice its configured duration, as Laravel's native Lua script specifies, and resets on acquisition after its `end`. Limits reset after the native end boundary; allow a small margin when retrying at an exact second. No invented remaining/reset values or identity details are returned.

For login, checks/reservations run IP → account/IP → account. If a later constraint rejects, earlier acquired allowances stay counted. Native response headers show its selected constraint, not a sum of all limits; a 429's retry timing describes the rejecting constraint. Clients may still encounter another limit on a later request. Fixed windows allow bursts near consecutive window boundaries. There is no automatic client retry or global bypass.

## Checkout, cancellation and idempotency

Throttling runs before CheckoutRequest and CheckoutService. A denied checkout inserts no order/items/redemption/outbox event/result, changes no inventory/promotion selection and leaves cart lines intact. Replays use the **same checkout limiter** as new purchases: an exhausted replay gets 429 without consulting or changing the original order. Keeping the same customer-scoped Idempotency-Key and retrying after the window expires returns the original 200 response, without another stock decrement, promotion redemption, cart clearing or placement event. Failed/throttled first attempts reserve no database idempotency key.

The existing atomic transactions, product/promotion lock order, conditional stock changes, unique identities and PostgreSQL concurrency constraints are unchanged. No CheckoutService edit was needed. Cancellation uses its own quota; denied cancellation changes no order status/restoration markers/inventory and creates no cancellation event. Once admitted its existing transaction and repeated-cancellation semantics remain intact.

## Queue/outbox isolation and outage handling

HTTP middleware never executes for ProcessOrderEvent, native queue workers, `orders:dispatch-outbox`, scheduled recovery, inspection/retry commands or internal database operations. The events/jobs/services/worker configuration from committed 7C remain untouched. Their independent queue connection/database and PostgreSQL durability/ownership checks retain effectively-once internal effects and lease recovery.

A **limiter Redis connection/operation failure fails closed with generic HTTP 503 `SERVICE_UNAVAILABLE`** before business services begin. The adapter catches only the limiter's PhpRedis exception, discards sensitive connection details and attaches no fabricated quota/retry values. There is no fallback counter or bypass. Availability is reduced during a limiter outage; abuse protection stays enforced. Guests can still receive 401 first. Database integrity does not depend on this admission control, and queue recovery can continue when its separately configured Redis is available. Monitor API 503 rates/Redis availability through the deployment's normal tooling.

Logical databases/prefixes isolate keys, not server memory/eviction/outages. The existing local Redis is disposable, uses allkeys-lru and has no persistence. Eviction/restart can reset a budget even when connectivity is healthy. For strict production enforcement, supply a private dedicated authenticated/TLS limiter Redis with a suitable no-eviction policy and adequate capacity. Keep queue durability and catalogue availability policies independently configured. No external deployment changes were authorized/performed.

## Tests, concurrency and performance inspection

Middleware remains enabled across the entire suite. TestCase guards PostgreSQL and limiter Redis DB 7, assigns a fresh UUID connection prefix per test and removes only keys under it. Existing independent HTTP worker harnesses receive the same explicit prefix/DB/no-URL values. Window tests move only their scoped hash's `end` into the past; Carbon travel alone cannot advance Laravel's native Redis wall clock. The catalogue benchmark advances that test's windows between its three 51-request scenarios outside measured samples; each scenario retains production thresholds and HTTP throttling.

Endpoint/security tests cover all policy categories/default headers, requests below/over the limit, registration/login, account-wide attacks, normalized/hash-only identity, malformed identifiers, customer/admin/token/IP isolation, forwarding trust, guest/forbidden precedence, real allowed/refused mutations, logout tokens, checkout/cancellation side effects/replays, window reset/TTL, JSON/no-Accept/HEAD, native header timing and real refused TCP outage/recovery.

Three eight-process admission scenarios use independent Redis connection IDs and explicit after-admission barriers before business work:

- Public three-request budget: exactly **3 × 200, 5 × 429**.
- Two customers, two-request budget each: each gets **2 × 200, 2 × 429**, independent counters.
- Checkout two-request budget with one shared customer/key: **1 × 201, 1 × 200 replay, 6 × 429**; exactly one order/redemption/outbox event, one stock deduction and no duplicate notification.

The cleanup case preserves sentinel keys outside its limiter prefix and in catalogue/queue databases. No FLUSHDB/FLUSHALL or unrelated queue operation is used. Existing PostgreSQL purchase/promotion/cancellation/admin contention still observes independent row-lock waiters. Queue and outbox suites retain real worker retry/failure/crash/lease evidence.

An executed operation test observes **one Redis EVAL per single-policy admitted or rejected request**; login uses up to three EVALs, stopping at the first rejection. The connection is reused within the application lifecycle; it is not persistently pooled by default. Key work/expiration uses Laravel's bounded Lua hash operations. No benchmark is presented as production load-test evidence. The adapter reduces the installed two-operation check/hit path to one atomic reservation; no Redis scan occurs in HTTP request handling (scoped key scans are test cleanup only).

```bash
# All database invocations must be sequential against the guarded test database.
docker compose exec -T api php artisan test --compact \
  tests/Feature/Http/Middleware/ThrottleApiRequestsTest.php \
  tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php \
  tests/Feature/Http/Controllers/Api/AuthControllerTest.php
docker compose exec -T api php artisan test --compact
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php
docker compose exec -T api php artisan test --compact \
  tests/Feature/Jobs/ProcessOrderEventTest.php \
  tests/Feature/Services/Order/OrderOutboxConcurrencyTest.php \
  tests/Feature/Services/Order/OrderOutboxServiceTest.php \
  tests/Feature/Models/OrderEventPersistenceTest.php
docker compose exec -T api php artisan test --compact tests/Feature/Services/Product/ProductCatalogueCacheTest.php
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
docker compose config --quiet
git diff --check
```

Postman descriptions document quotas/retry/headers; collection request bodies, token scripts and business flows are unchanged. Postman app execution is not claimed.

## Known limits and submission readiness

This is API request admission, not upstream volumetric DoS protection. Unmatched routes and method/route-constraint failures may never reach named middleware. Fixed windows, shared public IPs, continuing account-targeted denial, eviction resets and synchronized process clocks are explicit trade-offs. An outage yields 503 rather than a successful unthrottled business operation. There is no distributed fallback, adaptive scoring, automatic ban, production load test or new monitoring dashboard. The small native adapter must be rechecked with installed framework versions, and all its policies must stay pre-execution admission policies.

The implementation report and focused/full quality evidence support code review for the assessment. Before a separately authorized submission, review the uncommitted 7D diff and reconcile the employer's original brief, which is absent from the repository; choose production Redis/proxy settings if deployment is later requested. Do not interpret this report as authorization to commit 7D, merge, push, deploy or start submission.


## Executed final verification — October 6, 2026

| Check | Actual final result |
| --- | --- |
| Focused HTTP limiter/security/concurrency and authentication set | **103 passed, 1,125 assertions**, 3.88 s |
| Complete PostgreSQL/Redis regression | **874 passed, 5,810 assertions**, 47.86 s; all previous tests retained, 53 additional tests |
| Standalone real Redis admission/isolation/checkout/cleanup | **4 passed, 148 assertions**, 1.75 s |
| Five existing standalone PostgreSQL contention suites, catalogue caching and throttling enabled | **34 passed, 631 assertions**, 12.56 s, exit 0 |
| Native Redis jobs + outbox/model/service/concurrency set | **28 passed, 203 assertions**, 5.70 s |
| Real Redis catalogue caching suite | **50 passed, 588 assertions**, 12.51 s |
| Middleware route inspection | All **25** defined API route entries have their named adapter policy; authentication resolves before user policies |
| Pint `--dirty --format agent` | Passed |
| Composer `validate --strict` | Valid, exit 0 |
| Composer `audit` | No security vulnerability advisories, exit 0 |
| Compose configuration | Passed; no 7D Compose changes or exposed Redis port |
| Tracked/new-file whitespace and Postman JSON | Passed |
| Development business data | Read-only counts: orders/products/redemptions/outbox/notifications all zero |
| Final Redis test cleanup | Limiter DB 7, queue DB 5 and catalogue DB 3 each contained zero keys; scoped cleanup only |
| Final Git operation | HEAD and completed 7C branch stay at `49732ad851d8163e47b91be2979a0ffda8cc974d`; 7D changes unstaged/uncommitted |

Observed Redis CLIENT IDs in the final standalone limiter run: public **5082/5086/5081/5083/5084/5080/5085/5087**; customer isolation **5095/5090/5094/5092/5089/5091/5093/5096**; checkout **5104/5101/5099/5102/5105/5098/5103/5100**. Each scenario confirmed eight unique PHP PIDs and eight unique connections. Public outcomes were three admissions/five denials; each customer received two admissions/two denials; checkout retained one creation/one replay/six denials and one durable placement envelope.

Final queue/outbox evidence: claim PostgreSQL PIDs **58391/58392** held disjoint two-event batches; processing PIDs **58397/58398** were observed waiting on one outbox row and left one local effect. The Redis-enabled standalone purchase-key race retained **201/200** and one order/redemption; cancellation retained one restoration. These are local integration results, not production throughput evidence.

The catalogue benchmark initially reached the new correct 120/minute cap because its three scenarios issued 153 requests through one test client. Advancing only its native test windows between the three scenarios fixed that isolation issue without disabling middleware or increasing production budgets. All 50 catalogue cases and the full rerun then passed. The optional Redis-enabled contention invocation initially produced a duplicate `--configuration` warning from the Artisan wrapper despite passing its tests; rerunning directly through Pest with the same temporary configuration passed with exit 0. The temporary XML was outside the repository; its single observed catalogue generation key was removed by exact key, and the namespace was confirmed empty. No global Redis flush occurred.

The host-side Boost database query tool reported `could not find driver`; the development counts above were verified using a read-only psql query in the project PostgreSQL container. No dependency/environment fix or data write was introduced to work around that tool. The unrelated `postgres-local` container retained ID **feef0bbdac0cc1ff17e66513b3b27843d9d32908657bcc16211956ab4064102d**, start **2026-10-06T07:00:33.537768119Z**, restart count **0** throughout this task.

## Final 7D file inventory

**31 reviewed paths: 8 created, 23 modified.** No staged changes, dependency changes, migration changes or unreviewed unrelated paths.

Created:

- `app/Http/Middleware/ThrottleApiRequests.php`
- `app/Http/RateLimiting/ApiRateLimiters.php`
- `config/rate-limits.php`
- `config/trustedproxy.php`
- `docs/17-api-rate-limiting.md`
- `tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php`
- `tests/Feature/Http/Middleware/ThrottleApiRequestsTest.php`
- `tests/Fixtures/rate-limit-request.php`

Modified:

- `.env.example`
- `README.md`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `config/database.php`
- `docs/05-architecture.md`
- `docs/07-api-contracts.md`
- `docs/08-business-rules.md`
- `docs/09-testing-strategy.md`
- `docs/10-implementation-roadmap.md`
- `phpunit.xml`
- `postman/Ecommerce_Order_Promotion_API.postman_collection.json`
- `routes/api.php`
- `tests/Feature/Http/Controllers/Api/AuthControllerTest.php`
- `tests/Feature/Services/Admin/AdminConcurrencyTest.php`
- `tests/Feature/Services/Cart/CartConcurrencyTest.php`
- `tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php`
- `tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php`
- `tests/Feature/Services/Order/OrderConcurrencyTest.php`
- `tests/Feature/Services/Product/ProductCatalogueCacheTest.php`
- `tests/Fixtures/cart-promotion-request.php`
- `tests/Fixtures/cart-request.php`
- `tests/TestCase.php`


README, architecture, API contracts, security/business rules, test strategy, milestone roadmap and Postman collection descriptions now document the implemented policies. The earlier 7C report is retained as historical implementation evidence; its committed branch is unchanged. Current work stays on `feature/api-rate-limiting-7d` with parent commit `49732ad851d8163e47b91be2979a0ffda8cc974d`. The stop condition is met: no 7D commit, merge, push, deployment or submission work follows this verification.
