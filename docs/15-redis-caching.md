# Bonus Milestone 7B — Redis Product Catalogue Caching

Implemented and verified on October 6, 2026. Public product listings now use a dedicated Redis read-through cache with service-layer ownership, transaction-aware invalidation, generation-based race protection, PostgreSQL fallback, and real Redis tests. This milestone stops here. No queue/event processing, additional API rate limiting, new business features, frontend, commit, push, or deployment was performed.

## 1. Preflight and baseline

The initial Git tree was clean at `fae4fcc` (`feat: implement secure admin product and promotion management`). Reviewed Git history, README, Bonus 7A report, architecture/API/testing/roadmap documentation, Compose/Dockerfile, installed dependencies, public/admin catalogue controllers/services/repository/resource/requests/DTO, product seeding, checkout and cancellation transactions, inventory methods, and existing contention harnesses. `.ai/rules` is absent. Laravel best-practices/testing skills and installed-version Boost documentation were used. There was no project Redis service, PhpRedis extension, or Predis Composer package.

Runtime: container PHP **8.5.11**, Laravel **13.34.0**, Sanctum **4.3.3**, Pest **4.7.8**, PHPUnit **12.5.33**, Pint **1.32.1**, PostgreSQL 17. Host PHP 8.5.4 lacks PostgreSQL PDO/Redis support, so all database tests ran inside the API container against guarded `ecommerce_order_api_test` on `postgres:5432`.

Before any code changes:

```bash
docker compose exec -T api php artisan test --compact
```

Actual result: **743 passed, 4,129 assertions, 25.99 seconds**, matching the expected previous result. No development database reset, sample seeding, account creation, or migration was performed. The only recreated existing container was this project's API for PHP extension installation; unrelated containers were not modified or stopped.

## 2. Redis infrastructure and configuration

- Dedicated `redis:7.4-alpine` service; actual running server **7.4.11**.
- API image installs and enables pinned **PhpRedis 6.3.0**, confirmed through PECL metadata and `php --ri redis`. Composer dependencies/lock are unchanged.
- Private project default bridge network; no Redis host port, volume, or cross-project connection.
- Disposable cache: persistence disabled, 128 MB maxmemory, allkeys-lru eviction, health check, healthy dependency at startup.
- Catalogue uses Laravel's dedicated `catalogue` cache store/Redis connection. Authentication's existing database cache remains configured separately.

| Setting | Default / role |
|---|---|
| `CATALOGUE_CACHE_ENABLED` | true; false bypasses reads/writes/invalidation |
| `CATALOGUE_CACHE_STORE` | catalogue |
| `CATALOGUE_CACHE_NAMESPACE` | ecommerce:catalogue:{APP_ENV}; example explicitly uses local |
| `CATALOGUE_CACHE_PREFIX` | ecommerce-catalogue- |
| `REDIS_PREFIX` | deployment/application prefix; unique example supplied |
| `CATALOGUE_CACHE_TTL` | 45 seconds; clamped 1–300 |
| `REDIS_HOST`, `REDIS_PORT` | Compose redis:6379; Compose overrides REDIS_HOST |
| `CATALOGUE_REDIS_DB` | 2; PHPUnit forces isolated DB 3 |
| `CATALOGUE_REDIS_TIMEOUT` | 0.2 seconds for connect/read; zero catalogue client retries |
| `REDIS_USERNAME`, `REDIS_PASSWORD`, `CATALOGUE_REDIS_URL` | Optional private auth/TLS configuration |

Existing-checkout startup:

```bash
docker compose build api
docker compose up -d --no-deps redis api
docker compose exec -T api php --ri redis
docker compose exec -T redis redis-cli ping
# If previously cached:
docker compose exec -T api php artisan config:clear --no-interaction
```

Fresh installation retains the README's Composer/key/migration sequence; Compose starts this Redis dependency automatically. Native execution requires PhpRedis and a reachable privately configured Redis connection. Credentials stay in untracked environment/platform secrets. Production must provide private networking and appropriate auth/TLS; the supplied Compose service is a local development/test topology. Redis DB numbers are organizational separation, not an authorization boundary.

## 3. Service architecture and response preservation

```text
ProductController → ProductService → ProductCatalogueCache
  cache miss → ProductRepositoryInterface → EloquentProductRepository → PostgreSQL
  cache hit → scalar product snapshots → current paginator → ProductResource
```

ProductCatalogueCache owns key generation, reading/writing, TTL, generation invalidation, and cache failure isolation through Laravel's Cache abstraction. It is container-scoped; failures suppress further cache attempts for that request/lifecycle. No generic cache framework, model observer, queue, event pipeline, or Redis business lock is added.

Only public list/HEAD caching is in scope. Product detail and admin catalogue listing/detail still query PostgreSQL. Form Request validation always precedes cache access. Existing search across name/SKU/description, literal escaping, exact price bounds, availability, active visibility, ordering/ID tie-breaker, and pagination queries remain in the repository unchanged.

Stored values are allow-listed **scalar attributes plus total count**, not serialized Eloquent models, relations, resource objects, paginator objects, or complete HTTP responses. The fields are id/name/sku/description/price_minor/stock_quantity/status/created_at/updated_at. The existing ProductResource receives reconstructed model inputs, so enum/date/money formatting stays identical. Deployment currency is generated fresh. Pagination links/path/validated query spellings are constructed for each current request; hosts and unknown input cannot leak through cached links. Native `data`, `links`, `meta`, JSON behavior, request ID and no-store/private transport headers are retained.

## 4. Keys and TTL

Logical keys, before the deployment Redis/store prefixes:

```text
{namespace}:v1:public:generation
{namespace}:v1:public:{generation-uuid}:{sha256(normalized-query)}
```

The canonical ordered query contains search, min_price, max_price, nullable available, sort, direction, page and per_page from the validated typed ProductQuery DTO. Defaults, trimmed/empty search, integer inputs and recognized boolean representations normalize through the existing request. Different response-affecting query choices receive different digests. Equivalent normalized queries share data while retaining their current pagination links. Search case is not additionally folded: PostgreSQL remains responsible for its comparison semantics. Client text is hashed rather than embedded in Redis keys. Admin code never uses the public cache component.

A missing marker is initialized using atomic cache `add` with a new UUID and a one-day marker lifetime; invalidation replaces it with a fresh UUID stored indefinitely. Eviction/expiry cannot revive an earlier namespace. Old page keys expire naturally; invalidation performs one SET rather than enumerating/flushing entries. Warm paths use two Redis reads (marker/page); a normal miss adds a marker recheck and page write. A first-ever missing marker also requires add/read.

The **45-second** initial freshness budget balances repeated catalogue reads against inventory estimates. It is deliberately short and capped at 300 seconds by configuration. Elapsed cache/database-read time, rounded up to whole seconds, is deducted before population; slow reads can skip writing, and a configured one-second budget normally skips population. Typical default entries therefore receive about 44 seconds of Redis TTL. This conservative deduction prevents a normally slow read from acquiring a fresh full lifetime after it completes. Redis memory limits/eviction and expiring page entries bound cardinality/storage. There is no stampede lock or stale-while-revalidate/background job.

## 5. Invalidation coverage

| Supported mutation | Registration / behavior |
|---|---|
| Admin product creation | After repository creation inside admin transaction |
| Name, searchable SKU, searchable description (including null), price, status edits | After changed repository update inside admin transaction |
| Admin signed stock adjustment | Same row-locked admin update hook |
| Checkout inventory deduction | Once after successful workflow writes/clear, inside checkout transaction |
| Cancellation inventory restoration | Once after stock restoration and cancellation markers, inside order transaction |
| New ProductSeeder samples | Once per transactional insertion batch; existing samples preserved |

Every successful hook rotates the **whole public catalogue generation** because edits/stock can change membership, ordering, totals and page boundaries for many filters. No per-product key registry is required. Inactive edits/creations can also rotate; simplicity is preferred over another visibility-specific invalidation protocol. Empty PATCH returns the locked unchanged product; changed timestamp/resource inputs invalidate when a supplied edit is saved. Unchanged seeder reruns, permanent checkout replays and repeated cancellation do not rotate.

Promotion changes do not affect ProductResource/list filtering and need no listing invalidation. Cart mutations do not allocate inventory. Direct model writes, raw SQL, imports and future catalogue writers must call the component's post-commit method at their application boundary, or tolerate expiry; they are not automatically observed. Existing repository methods remain persistence-only.

## 6. Transactions, rollback and business safety

`invalidateAfterCommit()` registers Laravel `DB::afterCommit()` **inside** the service's transaction. It performs no Redis work before commit. Nested transaction callbacks wait for the outer commit; rollback or detected-concurrency retry discards callbacks from the failed attempt. Test transactions explicitly verify the generation stays unchanged through uncommitted creation/update/checkout/cancellation and after outer rollback. Catalogue reads with an open transaction bypass Redis entirely, so uncommitted/rolled-back rows never populate the shared cache.

Checkout's Cart → ascending Products → Promotion locking, active/stock checks, current prices, exact arithmetic, snapshots, conditional PostgreSQL decrement, redemption ledger, cart clear, and permanent customer-scoped idempotency keys are unchanged. Cancellation retains Order → ascending Products, overflow-safe conditional increments, persisted equal cancellation/restoration markers, and idempotent repeat behavior. Redis is never authoritative for any business input. Tests deliberately leave stale price/stock/status in Redis and prove checkout still uses/rejects the current PostgreSQL rows.

Cache exceptions are caught inside the actual after-commit callback. Therefore Redis failure cannot escape from a committed purchase and make it appear unsuccessful. A test registers an earlier post-commit fault callback during order creation, makes the Redis endpoint unreachable only at transaction level zero, then verifies 201 purchase, persisted order/stock/cart, unchanged old Redis generation, 200 same-key replay, and successful cancellation/restoration.

## 7. Stale-repopulation race and consistency limits

A cache miss captures generation G before reading PostgreSQL. A mutation commits and rotates to H. Before publication the reader checks the current marker; if it differs, it skips writing. If another invalidation occurs between that check and SET, the write still uses G, so new readers using H cannot reach it. UUID replacement/atomic missing-marker initialization also prevents namespace reuse after eviction. The deterministic race test performs the old database read, commits an admin update, then returns the old page to the loader; the next public request must fetch and cache the new page.

The original caller may receive its earlier read. This is **eventual consistency**, not atomic PostgreSQL/Redis consistency. Readers between commit and callback, failed invalidation, a process crash after database commit, or privileged unsupported writes can leave a previous entry reachable until its page TTL expires (at most the configured 45-second default lifetime after storage). Elapsed-read deduction reduces stale publication lifetime but is not a strict wall-clock scheduling guarantee. No durable invalidation retry/outbox is implemented. Changing namespaces or disabling cache is an operational escape hatch. Inventory correctness does not depend on these visibility windows.

## 8. Redis failure behavior and diagnostics

Only cache operations execute inside the failure boundary. PhpRedis RedisException and invalid cache-store/connection configuration cause PostgreSQL fallback; the repository loader and unrelated application errors are outside that catch and propagate normally. Refused cache writes/invalidation are treated as cache failures. Connection/read timeout is 200 ms with no client retry; name resolution/OS scheduling can add latency. Subsequent operations in that lifecycle are skipped after failure.

A warning records operation/store/exception class, without connection messages, endpoint internals, credentials, tokens or request payload. A local file-cache marker limits warnings to one per namespace per minute per shared application filesystem. Multiple hosts may each log once; this is not cluster-global throttling. Diagnostic-sink failures are isolated so they cannot turn a committed purchase into an error; broken sinks can lose diagnostics. There is no secondary stale response cache: unavailable Redis goes directly to PostgreSQL. Existing authentication caching is unaffected.

## 9. Tests and exact quality results

| Executed check | Actual result |
|---|---|
| Complete pre-change PostgreSQL baseline | **743 passed, 4,129 assertions**, 25.99s |
| Real Redis caching file (50 new cases) | **50 passed, 588 assertions**, 11.21s |
| Final focused caching + ProductService + ProductSeeder files after Pint | **59 passed, 612 assertions**, 11.36s |
| Complete PostgreSQL/Redis regression | **793 passed, 4,717 assertions**, 36.45s |
| Standalone existing contention suites with catalogue caching enabled / real Redis | **34 passed, 631 assertions**, 14.24s |
| Pint `vendor/bin/pint --dirty --format agent` | Exit 0; final result passed |
| Composer `validate --strict` | Exit 0; composer.json valid |
| Composer `audit` | Exit 0; no security vulnerability advisories found at execution |
| Compose `config --quiet` | Exit 0 |
| Git tracked and new-file whitespace checks | Passed |
| Docker rebuild / runtime check | PhpRedis 6.3.0 enabled; Redis 7.4.11 healthy; no host port |
| Live public listing smoke check | HTTP 200; data/links/meta; no-store/private and X-Request-ID headers |

The new tests exercise real Redis on every case, not an exclusively mocked cache. Failure tests include a genuine refused TCP connection and separate invalid configuration cases. Other coverage: every query-key dimension, normalized reuse, warm validation, inactive isolation, pagination/host reconstruction, detail/admin isolation, all invalidation paths/membership changes, nested rollback, transactional-read bypass, stale-repopulation, marker eviction, real expiration, population failure, safe/throttled logs, unrelated application exceptions and Redis sentinel preservation. DatabaseMigrations creates committed fixtures for actual post-commit behavior; cancellation fixtures are removed before the existing guarded downgrade.

Each caching test uses Redis DB 3 with its own UUID namespace, checks its dedicated target, deletes only matching keys, and asserts they are gone. No test or cleanup invokes FLUSHDB/FLUSHALL. All suites ran sequentially against the guarded test database. Final Redis DB 3 contained zero keys after scoped test/concurrency cleanup. No existing test was deleted.

Early verification identified teardown incompatibility with retained cancelled fixtures and a doubled PhpRedis prefix in cleanup; both were corrected and rerun. Outage fixtures were also corrected to rebuild RedisManager after adding a connection so tests truly reached refused TCP rather than merely missing configuration. The final results above supersede those development failures.

Repeat focused/full checks:

```bash
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Product/ProductCatalogueCacheTest.php \
  tests/Feature/Services/Product/ProductServiceTest.php \
  tests/Feature/Database/Seeders/ProductSeederTest.php
docker compose exec -T api php artisan test --compact
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
git diff --check
```

## 10. Independent-process PostgreSQL contention with Redis enabled

A temporary PHPUnit configuration outside the repository overrides the existing false catalogue-cache test default, uses Redis DB 3 and one unique testing namespace, and keeps the PostgreSQL guard. Child HTTP workers inherit these environment values. The actual Redis generation marker was observed in that namespace before scoped cleanup, confirming real post-commit cache operations executed. This temporary configuration was not committed.

The **34 cases = 27 real overlap cases + 7 injected retry/exhaustion cases** retain their existing independent PHP/PostgreSQL worker, observer and two-observed-lock-waiter barriers. Injected errors test bounded retry; they are not observed deadlock cycles. Representative final observations:

| Case | Backend PIDs observed waiting | HTTP results | Persisted result |
|---|---|---|---|
| Last stock allocation | 43972 / 43971 | 409 / 201 | One order, stock 2 |
| Last promotion use | 43977 / 43976 | 201 / 409 | One discounted order/redemption |
| Same checkout key | 43982 / 43981 | 201 / 200 | Same order ID, one redemption, stock 3 |
| Same order cancellation | 43996 / 43995 | 200 / 200 | One order update and one restore, stock 10 |
| Admin +3 vs checkout 4 | 44020 / 44019 | 200 / 201 | One order, stock 4 |
| Two partial admin edits | 44093 / 44094 | 200 / 200 | Both edits retained |

Also covered: carts/promotion selection, ascending multi-product checkout, both queued orders for admin negative/positive stock versus checkout/restoration, cancellation versus checkout, promotion limit and discount changes, and rollback/retry exhaustion. Redis changes did not alter the original locking protocol or tested outcomes.

Repeat the Redis-enabled run without changing committed PHPUnit defaults:

```bash
docker compose exec -T api php <<'PHP'
<?php
$xml = new DOMDocument;
$xml->load('phpunit.xml');
$xml->documentElement->setAttribute('bootstrap', '/var/www/html/vendor/autoload.php');
$xpath = new DOMXPath($xml);
foreach ($xpath->query('//directory') as $node) {
    $node->nodeValue = '/var/www/html/'.$node->nodeValue;
}
foreach ($xpath->query('//env') as $node) {
    if ($node->getAttribute('name') === 'CATALOGUE_CACHE_ENABLED') {
        $node->setAttribute('value', 'true');
    }
    if ($node->getAttribute('name') === 'CATALOGUE_CACHE_NAMESPACE') {
        $node->setAttribute('value', 'ecommerce:catalogue:testing:concurrency-'.bin2hex(random_bytes(8)));
    }
}
$xml->save('/tmp/ecommerce-redis-concurrency-phpunit.xml');
PHP
docker compose exec -T api php artisan test --compact \
  -c /tmp/ecommerce-redis-concurrency-phpunit.xml \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php
```

After that optional run, remove only its exact namespace's marker using the dedicated project's redis-cli DEL (read the namespace from the temporary XML and account for configured Redis/cache prefixes); never use a database flush. Normal integration-file teardown handles its own exact UUID keys automatically.

## 11. Actual local performance measurements

Repeatable benchmark:

```bash
docker compose exec -T api php artisan test --compact --group=catalogue-benchmark
```

Results from the final full-suite execution on the supplied development host, **200 active factory products**. Each variant has one cold request and 25 samples each with caching disabled and warm caching enabled. Timings cover Laravel HTTP-kernel test requests in one already booted process, including resource serialization; they exclude network/server bootstrap costs. There is no timing pass/fail threshold.

| Listing | First cache miss (ms) | Uncached median (ms) | Warm cached median (ms) | Product SELECTs, miss → hit |
|---|---:|---:|---:|---:|
| Default | 2.880 | 2.623 | 2.431 | 2 → 0 |
| Search/price/availability/sort | 3.450 | 3.074 | 2.760 | 2 → 0 |
| Page 4 / 20 entries / name ascending | 3.909 | 3.519 | 3.162 | 2 → 0 |

The measured latency improvements are modest for this small local database; eliminating repeated product count/data queries is the directly demonstrated benefit. Cold caching adds Redis work. These results do not establish production throughput, p95 latency, Redis capacity, distributed consistency, or the need for additional indexes. Larger datasets, realistic concurrency, separate hosts and production workloads require a separate benchmark.

## 12. Documentation and file inventory

Updated README (stack, configuration, Docker startup, failure behavior, test/benchmark commands), architecture, API freshness semantics, testing strategy and implementation roadmap. This focused report records configuration, key/TTL design, transactions, invalidation, races, failures, trade-offs, evidence, and repeatable checks. Existing milestone reports remain historical.

Created (3):

- `app/Services/Product/ProductCatalogueCache.php`
- `tests/Feature/Services/Product/ProductCatalogueCacheTest.php`
- `docs/15-redis-caching.md`

Modified (19):

- `.env.example`
- `Dockerfile`
- `compose.yaml`
- `README.md`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Product/ProductService.php`
- `app/Services/Product/ProductAdministrationService.php`
- `app/Services/Checkout/CheckoutService.php`
- `app/Services/Order/OrderService.php`
- `config/cache.php`
- `config/catalogue.php`
- `config/database.php`
- `database/seeders/ProductSeeder.php`
- `phpunit.xml`
- `tests/Feature/Services/Product/ProductServiceTest.php`
- `docs/05-architecture.md`
- `docs/07-api-contracts.md`
- `docs/09-testing-strategy.md`
- `docs/10-implementation-roadmap.md`

No controller, resource, request, repository, business schema, route, Composer dependency, lockfile or private `.env` change was necessary. The existing service test only receives the new cache constructor dependency. Git remains at `fae4fcc`: **19 tracked modified files and 3 untracked new files**, all unstaged; no commit or push.

## 13. Known limitations and next milestone

Catalogue caching is a short-lived estimate. Global generation rotation can reduce cache reuse under frequent writes, and concurrent misses may repeat database work. The component does not provide strict cross-store consistency, durable invalidation retries, stampede suppression, per-product targeted eviction, or automatic raw-SQL/model-write invalidation. Cache read availability assumes the supplied PhpRedis extension is installed. Very small TTLs/long reads can bypass population. Warning limits are filesystem-scoped and broken diagnostic sinks can lose warnings. Native tests remain unsupported without the equivalent guarded Compose database topology.

Redis eviction/restart loses cached data safely and repopulates from PostgreSQL. Cache entries use scalar snapshots, so future ProductResource fields must be reviewed against the allow-list and payload version. The service transaction contract and original PostgreSQL locking protocol remain mandatory for future writers. No production-scale performance or universal deadlock-freedom claim is made.

`postgres-local` retained ID `feef0bbdac0cc1ff17e66513b3b27843d9d32908657bcc16211956ab4064102d`, start time `2026-10-06T07:00:33.537768119Z`, and zero restarts from preflight through final verification. This project's PostgreSQL container was not recreated or stopped. No unrelated Redis container was used or modified.

Recommended next separately scoped bonus: **durable order notifications using an outbox/queue design**, with post-commit failure isolation and idempotent delivery. That work, order events and additional API throttling are not implemented by this milestone.
