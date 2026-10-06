# Bonus Milestone 7A — Secure Admin Product & Promotion Management

Implemented and verified on October 6, 2026. This milestone adds product and promotion administration to the completed mandatory API while preserving customer authorization, pricing, checkout, cancellation, snapshots, and redemption behavior. Work stops here: Redis caching, order queues and additional rate limiting are deferred. No commits, pushes or deployments were created.

## 1. Preflight and baseline

Inspected Git status/history, README, architecture, the complete mandatory-scope audit, recorded employer requirements, authentication/Sanctum, all five repository contracts and implementations, Product/Promotion models and services, cart/checkout locking, cancellation and promotion usage accounting. HEAD was `0e3a39c` (`feat: implement order management and concurrency-safe cancellation`). Eleven tracked files already had modifications; docs/13 and the Postman collection were already untracked. These changes were preserved, including the existing catalogue search and concurrency fixes.

The separately supplied employer brief is absent from the repository. The recorded docs/02 requirements and the supplied Bonus 7A request are the available requirements. `.ai/rules` is absent. The project Laravel/testing skills and installed-version Boost documentation informed implementation. Installed packages: Laravel 13.34.0, Sanctum 4.3.3, Pest 4.7.8, Pint 1.32.1; PHP 8.5 runtime and PostgreSQL 17 via the existing project Compose services.

The initial host invocation could not verify the baseline because host PHP lacks pdo_pgsql. Boost's host schema view was also empty; effective PostgreSQL schemas were inspected using `db:table` in the API container. The actual pre-change baseline was then executed with:

```bash
docker compose exec -T api php artisan test --compact
```

**562 tests passed, 3,007 assertions, 21.31 seconds.** No code was edited before this baseline completed. No operation targeted the unrelated postgres-local container.

## 2. Administrator authorization

`users.is_admin` is explicit and defaults false. User casts it to boolean, hides it during model serialization, and keeps it outside fillable fields. Registration supplies only customer name/email/password to its repository; forged flags, roles or permissions never confer authority. No public role-management or administrator-registration route exists.

All eight admin routes use `auth:sanctum`, followed by auto-discovered ProductPolicy/PromotionPolicy class-level permissions. Authentication failures return 401. Customers return 403 before payload validation or lookup, including requests for missing IDs. Policies allow only a stored true flag; wildcard token abilities alone do not grant admin access. Services authorize again for direct callers. There is no global administrator bypass of CartPolicy or OrderPolicy; customer routes still require ownership. Existing bearer-only Sanctum configuration, password handling and token policy are unchanged.

## 3. Database migration and factories

`database/migrations/2026_10_06_173445_add_is_admin_to_users_table.php` adds one non-null boolean with default false. Existing users stay unprivileged, proven by a migration test inserting a user before reapplying the additive migration. Existing product/promotion checks, normalized uniqueness, history/ledger FKs and money representation are retained. No counter, reservation or RBAC tables are added.

After verifying that all 14 prior migrations had run and only this migration was pending, the new migration was applied to the existing **local** project database with `php artisan migrate --no-interaction` in the API container (4.90ms). No reset, seed or account creation was performed. Tests use only the guarded ecommerce_order_api_test database. Rollback drops the flag and loses administrator assignments; use a forward correction when retaining them matters.

UserFactory adds `administrator()` for test fixtures. Existing ProductFactory, PromotionFactory and ledger factories are reused.

## 4. Product endpoints

| Method | Route | Behavior |
|---|---|---|
| GET | `/api/admin/products` | Stable pagination and catalogue filters; includes inactive by default; optional status |
| GET | `/api/admin/products/{id}` | ProductResource, including inactive products |
| POST | `/api/admin/products` | Create, return 201; nonnegative initial stock |
| PATCH | `/api/admin/products/{id}` | Partial property edits and signed stock adjustment, return 200 |

Names/SKUs are trimmed; SKU is uppercase with the existing normalized unique index, including legacy lowercase rows. Prices remain integer minor units. Product creation supports description, price, status and stock. PATCH supports name, SKU, nullable description, price and status, plus stock_adjustment. Inactive products remain hidden from public list/detail. No DELETE endpoint exists. Historical order item fields are never updated.

## 5. Promotion endpoints

| Method | Route | Behavior |
|---|---|---|
| GET | `/api/admin/promotions` | Stable pagination, optional is_active, aggregate redemptions_count |
| GET | `/api/admin/promotions/{id}` | Editable fields and aggregate usage; no customer ledger identities |
| POST | `/api/admin/promotions` | Create canonical code and existing promotion properties, return 201 |
| PATCH | `/api/admin/promotions/{id}` | Partial edits, activation/deactivation, dates, caps and usage limits |

Uses the existing percentage/fixed enum, basis points and minor-unit fields. Code may be edited while retaining the same promotion ID; previously selected carts still refer to that ID. Historical promotion snapshots and redemption identity/discount/timestamps remain unchanged. Future checkout revalidates the edited promotion through the original eligibility/calculation services. No DELETE or ledger-edit endpoint exists.

## 6. Services and repositories

```text
AdminProductController → ProductAdministrationService → ProductRepositoryInterface
  → EloquentProductRepository → Product → PostgreSQL
AdminPromotionController → PromotionAdministrationService → PromotionRepositoryInterface
  → EloquentPromotionRepository → Promotion → PostgreSQL
GrantAdministrator → AdministratorProvisioningService → UserRepositoryInterface
  → EloquentUserRepository → User → PostgreSQL
```

Product repository adds create/update and permits an explicit null visibility filter for admin pagination; ProductService still always requests Active. Existing search/sort mapping and row locks are reused. Promotion repository adds pagination/detail/counts, create/update and largest-per-customer consumption aggregation. User repository adds an explicit administrator grant. Existing one-to-one bindings suffice; no generic repository/base service or new dependency is added.

Six admin Form Requests, ProductPolicy, PromotionPolicy, three typed domain conflict exceptions and AdminPromotionResource complete the HTTP boundary. ProductResource is reused. Controllers handle HTTP only; services own transactions/invariants; repositories own queries/locks/writes. Checkout, cancellation, PromotionCalculator and PromotionService are not duplicated or modified.

## 7. Validation and errors

Actual JSON integers are required for prices, stock, adjustments, promotion values, monetary thresholds/caps and limits. Strings, fractional/whole-number floats, booleans, negatives where prohibited and out-of-range values are rejected. Price/initial stock/minimum are 0..PHP_INT_MAX; discounts/caps/non-null limits are positive; percentage value is at most 10000 basis points. Adjustment range is −PHP_INT_MAX..PHP_INT_MAX excluding zero. Input is validated and allow-listed again before persistence; client IDs, owner fields, ledger counts and timestamps cannot change protected attributes.

Product name/SKU are required on create and bounded to 255 characters; nullable description is bounded to 10,000. Promotion codes follow canonical ASCII syntax and max length 64. Status uses the existing enum; is_active requires a JSON boolean. Dates require explicit-offset ISO timestamps with whole seconds or exactly six fractional digits, accept Z, preserve offsets/microseconds in storage, and emit UTC. Dates must be strictly ordered. PATCH omitted fields retain values; explicit null clears nullable fields. Combined type/value/date rules are rechecked against the locked row. Empty PATCH is a no-op.

Existing error envelope and request-ID/no-store headers are preserved. Field/uniqueness/combined-value errors are 422 VALIDATION_FAILED. Underflow/overflow is 409 INVENTORY_ADJUSTMENT_CONFLICT. Limits below consumed usage are 409 PROMOTION_USAGE_LIMIT_CONFLICT. Exhausted recognized contention is 409 ADMINISTRATION_CONFLICT. Missing/invalid IDs are 404 and unexpected errors are sanitized 500 without SQL or traces.

## 8. Transactions and concurrency decisions

Product PATCH takes FOR UPDATE on one product, reads its current stock, bounds the signed delta before addition, persists all supplied properties and commits. This serializes with cart modifications, checkout deductions and cancellation restoration on the same product. Absolute stock replacement is rejected on PATCH; creation can establish initial stock. Negative stock and overflow roll back every field. Separate delta requests are **not idempotent**; a lost response must be reconciled instead of blindly replayed. Internal transaction retries roll back each earlier attempt before re-reading/reapplying.

Promotion PATCH takes FOR UPDATE on the same promotion row checkout locks before eligibility counts/redemption insert. After acquiring it, PATCH merges retained properties and validates the result. Supplied non-null global limits cannot be below total ledger use; customer limits cannot be below the highest individual customer's ledger count. Equality is allowed and stops subsequent eligibility; null removes a limit. Failed reductions roll back every other supplied field. Historical ledger rows are never changed, and cancellation does not re-credit them.

Admin mutations lock only Product or Promotion and acquire no Cart/Order/dependent-domain row afterward. Checkout retains Cart → Products ascending ID → Promotion; cancellation retains Order → Products ascending ID. Promotion attachment/removal remains an unlocked eligibility estimate under its existing cart lock; an admin edit may make a selection ineligible, which checkout catches. There is no reverse lock edge or inventory reservation. Services use short transactions with three attempts for framework-recognized concurrency failures; exceptions are mapped outside rollback. Uniqueness and database checks remain authoritative.

## 9. Provisioning and API examples

Use an existing locally registered account with your own private password. Replace the synthetic email below with that email. APP_ENV must be local; the command refuses production, staging and testing and has no force option:

```bash
docker compose exec -T api php artisan migrate --no-interaction
docker compose exec -T api php artisan admin:grant developer@example.test --no-interaction
```

The grant is repeatable, creates no account/password/token, and changes no password. Login through the existing auth endpoint to obtain a bearer token. Keep credentials/tokens outside shared artifacts. No development account was provisioned by this implementation.

The following examples use caller-set API_BASE (without /api), ADMIN_TOKEN and captured ADMIN_PRODUCT_ID / ADMIN_PROMOTION_ID; no real credentials are included:

```bash
curl -X POST "$API_BASE/api/admin/products" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"name":"Desk Lamp","sku":"LAMP-01","price_minor":1899,"stock_quantity":10,"status":"active"}'

curl -X PATCH "$API_BASE/api/admin/products/$ADMIN_PRODUCT_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"price_minor":2099,"stock_adjustment":-2,"status":"inactive"}'

curl -X POST "$API_BASE/api/admin/promotions" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"code":"SAVE20","type":"percentage","value":2000,"minimum_cart_amount_minor":10000,"maximum_discount_minor":2500,"global_usage_limit":100,"per_customer_usage_limit":2,"is_active":true}'

curl -X PATCH "$API_BASE/api/admin/promotions/$ADMIN_PROMOTION_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"type":"fixed","value":500,"maximum_discount_minor":null,"is_active":false}'
```

## 10. Tests and exact quality results

| Check | Executed result |
|---|---|
| Pre-change PostgreSQL baseline | **562 passed, 3,007 assertions**, 21.31s |
| Final focused admin/policy/provisioning/migration tests | **181 passed, 1,122 assertions**, 9.28s |
| Complete PostgreSQL regression after PHP formatting | **743 passed, 4,129 assertions**, 24.20s |
| Existing and new standalone concurrency files | **34 passed, 631 assertions**, 11.43s |
| Pint `vendor/bin/pint --dirty --format agent` in API container | Exit 0; final result passed |
| Composer `validate --strict` | Exit 0; composer.json valid |
| Composer `audit` | Exit 0; no security vulnerability advisories found at execution time |
| Git tracked and untracked whitespace checks | Passed |
| Postman static verification | 27 requests cover all 25 API routes; 28 JavaScript scripts and 11 JSON payloads checked; credential/token defaults empty |
| Postman execution | Not performed; automated endpoint tests exercise the implementation |
| Local additive migration | Existing 14 migrations retained; only the new migration applied successfully |

Commands for repeatable verification:

```bash
docker compose exec -T api php artisan test --compact \
  tests/Feature/Http/Controllers/Api/AdminAuthorizationTest.php \
  tests/Feature/Http/Controllers/Api/AdminProductControllerTest.php \
  tests/Feature/Http/Controllers/Api/AdminPromotionControllerTest.php \
  tests/Feature/Policies/ProductPolicyTest.php \
  tests/Feature/Policies/PromotionPolicyTest.php \
  tests/Feature/Services/Auth/AdministratorProvisioningServiceTest.php \
  tests/Feature/Models/UserAdministratorMigrationTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php
docker compose exec -T api php artisan test --compact
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php
docker compose exec -T api vendor/bin/pint --dirty --format agent
composer validate --strict
composer audit
git diff --check
```

Run database suites sequentially. The 181 new tests cover every admin route's token/customer boundary, policy grants/denials, registration/mass-assignment protection, current stored flag, web-session refusal, service authorization, local provisioning, legacy migration defaults, create/update/read/validation/uniqueness/status, integer boundaries, snapshot/ledger preservation, rollback, actual contention and injected retries. All existing 562 tests are retained. Early failures identified test-fixture encoding/transaction/timestamp differences and Z date validation; those were corrected and affected suites rerun before the passing results above.

## 11. Observed concurrency evidence

New admin coverage includes **13 real overlap cases and 3 injected retry/exhaustion cases**. Combined with the existing 14 overlap and 4 injected cases, the standalone set is **27 real overlap + 7 injected = 34 tests**. Injected SQLSTATE 40P01 errors validate retry/rollback; they are not observed deadlock cycles.

Fixtures are committed via DatabaseMigrations. The reused HTTP-kernel worker runs in an independent PHP process, has its own PostgreSQL connection/PID, receives bearer tokens via stdin, checks the dedicated test target and uses bounded timeouts. An independent observer requires two distinct active PostgreSQL lock waiters whose queries name the barrier row's table and FOR UPDATE, before the parent releases its lock. The first worker is observed waiting before launching the second; tests exercise both queue orders. Assertions check persisted stock, order/cart state, ledger counts and original records, and lock SQL. Cleanup releases barriers/stops workers and removes only guarded test fixtures before migration teardown.

Representative final standalone observations (status order is admin, customer):

| Operation / first queued | Worker PIDs, also observed waiting | Statuses | Persisted result |
|---|---|---|---|
| Stock +3 / purchase 4, admin first | 38120 / 38121 | 200 / 201 | Initial 5 → final 4; one order |
| Stock +3 / purchase 4, checkout first | 38126 / 38125 | 200 / 201 | Final 4; both changes retained |
| Stock −3 / purchase 4, admin first | 38130 / 38138 | 200 / 409 | Stock 2; no order; cart retained |
| Stock −3 / purchase 4, checkout first | 38143 / 38142 | 409 / 201 | Stock 1; one order |
| Stock +3 / restore 4, admin first | 38147 / 38148 | 200 / 200 | Stock 8; order cancelled |
| Stock +3 / restore 4, cancellation first | 38153 / 38152 | 200 / 200 | Stock 8; both changes retained |
| Global limit 2→1 with one use, admin first | 38157 / 38158 | 200 / 409 | Limit 1, usage 1, no new order |
| Same global reduction, checkout first | 38163 / 38162 | 409 / 201 | Limit 2, usage 2, one new order |
| Customer limit 3→2 with two uses, admin first | 38168 / 38169 | 200 / 409 | Customer limit/use 2, no new order |
| Same customer reduction, checkout first | 38174 / 38173 | 409 / 201 | Customer limit/use 3, one new order |

Additional real cases verify coherent old/new promotion type/value/discount snapshots in both queued orders and distinct partial edits from two administrators. These establish specified contention scenarios, not universal deadlock freedom or a production load benchmark.

## 12. Security verification

Guests, invalid/revoked tokens and web-session-only users cannot administer. Ordinary customers with wildcard tokens and forged role/permission fields receive 403 on all eight routes. Registration and mass assignment cannot set the flag. Changing the stored flag invalidates admin authority on subsequent authenticated requests even with an existing token. Local provisioning refuses other environments and requires an existing account.

Input validation, service field allow-lists, bound Eloquent queries, mapped catalogue sort identifiers and authoritative uniqueness/constraints protect mutations. Arithmetic bounds precede stock addition. Whole-number JSON floats are explicitly tested as invalid money. Domain/conflict and unexpected-error responses retain sanitization, request IDs and no-store headers. No sensitive fields/ledger identities are added to customer resources. No dependency changes, real credentials, public administrator registration, broad customer-ownership bypass or hard-delete routes are introduced.

## 13. Documentation changes

README and docs/02,04,05,06,07,08,10 now cover delivered scope, endpoints, auth, schema, validation, transactions, retirement, stock semantics and safe provisioning. docs/13 retains its historical audit and links to this current follow-up. This report records current evidence, examples, limitations and the cache compatibility map. Existing milestone documentation is retained with later additions clearly distinguished.

## 14. Postman changes

The existing 18 customer requests remain. A separate Admin folder adds login plus all eight admin endpoints, captures independent admin product/promotion IDs and admin_bearer_token, documents prerequisite local provisioning, and warns about non-idempotent stock deltas. Product/promotion SKU/code variables make creation prerequisites explicit. Customer and admin email/password/token defaults are all empty. Static JSON/payload/route/script checks passed; no Newman/Postman execution is claimed.

## 15. Git and workspace state

Existing changes were preserved and all work remains unstaged: **23 modified tracked files and 30 untracked files**, including the two pre-existing untracked artifacts and 28 new milestone files. HEAD remains `0e3a39c` and the index is empty. Pre-existing ignore/discovery changes and the two existing test fixes were compared against the preflight patch and retained byte-for-byte; the existing repository search fix is retained alongside the new admin methods. No commit, push, history rewrite, dependency change, unrelated project edit or unrelated Docker/container action was performed. Temporary test logs and validation data remain outside the repository under /tmp; this report contains durable observed results.

Final local schema inspection confirms `users.is_admin` defaults false, 15 migrations are applied, and users/products/promotions/carts/orders/redemptions remain empty. No development accounts, tokens or sample records were created.

## 16. Known limitations

- The employer's original separate brief remains unavailable; requirements must still be reconciled with it before submission.
- Stock deltas have no HTTP idempotency key or separate adjustment audit ledger. Concurrent safety does not prevent repeated separate requests from adding/removing stock again.
- Provisioning is development-only. No production grant/revoke workflow, full RBAC or administrator-specific token-expiry/scoping policy is added. Operators controlling configuration/SQL remain trusted.
- Ledger limit invariants assume every supported redemption/administration writer follows the same promotion lock protocol; privileged SQL can bypass it. Counts and largest-customer grouping grow with ledger history.
- The API uses signed 64-bit bounds; JavaScript clients need lossless handling above 2^53−1. No large-data/load benchmark or universal schedule/deadlock guarantee is claimed.
- Postman was structurally verified, not executed; the host PHP lacks pdo_pgsql, so database commands/tests use the project container. No static-analysis dependency was installed.
- No inventory reservation, deletion endpoints, Redis caching, order queue or new throttle is included.

## 17. Future Redis catalogue compatibility

The next milestone should invalidate catalogue list/filter/sort results and applicable detail entries for **every committed product mutation**:

| Mutation | Catalogue effect / required invalidation |
|---|---|
| Product creation | All list/search/filter pages; applicable detail/negative lookup |
| Product name update | Detail, name search and name-sorted pages |
| Product SKU update | Detail and SKU-search pages |
| Product description update | Detail and description-search pages |
| Product price update | Detail, price filters and price-sorted pages |
| Product activation/deactivation | Every visibility-dependent list and detail, including negative lookups |
| Admin stock adjustment | Detail, available=true/false lists and unfiltered stock fields |
| Checkout stock deduction | Same stock/detail/filter invalidation |
| Cancellation stock restoration | Same stock/detail/filter invalidation |

Cache invalidation belongs after successful transaction commit, including admin, checkout and cancellation paths; failed/retried attempts must not publish intermediate stock or invalidate based on rolled-back work. Use complete normalized filter/page/sort/currency keys and consider a catalogue generation so late stale fills cannot repopulate the new generation. Bound TTL and handle the database/Redis failure window deliberately. Invalidation must cover the existing Product repository's deductStock/restoreStock writers as well as admin writes, rather than depending only on controller hooks.

Keep checkout/cart eligibility authoritative from PostgreSQL and preserve current integer/status/resource contracts. Promotion eligibility and usage must not depend on cached ledger counts. These are documented requirements/recommendations only; no Redis client, cache implementation, background order work or new rate limiter was added.
