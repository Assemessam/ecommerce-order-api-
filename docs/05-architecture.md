# 05 — Architecture

## System context

The system is a single Laravel REST API backed by PostgreSQL. Clients communicate over HTTPS and authenticate protected routes with Sanctum bearer tokens. The modular monolith keeps transactions local and is appropriate for the assessment's size and consistency requirements.

## Request flow

```text
HTTP client
  → route + middleware
  → Form Request
  → thin controller
  → domain/application service
  → repository interface
  → Eloquent repository
  → PostgreSQL
```

API Resources shape successful responses. A central exception renderer converts validation, authentication, authorization, missing-resource, and domain exceptions into the documented error envelope.

## Responsibilities

### Controllers

- Accept already-validated Form Request data.
- Call one service use case.
- Return an API Resource/collection and status code.
- Contain no Eloquent query or business calculation.

### Services

- Enforce business rules and coordinate repositories.
- Accept/return domain-friendly values or DTOs, not HTTP requests/responses.
- Raise typed domain exceptions.
- Own transactions that span a complete business workflow.
- `CheckoutService` owns checkout; `OrderService` owns cancellation.

### Repositories

- Implement domain-specific persistence contracts.
- Build Eloquent queries, eager loading, pagination, writes, and query-specific row locks.
- Participate in the caller's transaction.
- Never independently commit/roll back the full checkout or cancellation workflow.

### Models and database

- Models express relations, casts, scopes, and local persistence behavior—not orchestration.
- PostgreSQL constraints provide the final defense for uniqueness, references, non-negative values, and valid scalar ranges.

## Directory convention

```text
app/
  Contracts/Repositories/<Domain>RepositoryInterface.php
  DTOs/<Domain>/...
  Enums/...
  Exceptions/Domain/...
  Http/Controllers/Api/...
  Http/Requests/<Domain>/...
  Http/Resources/...
  Models/...
  Policies/...
  Repositories/Eloquent/<Domain>Repository.php
  Services/<Domain>/<UseCase>Service.php
```

Directories are introduced only when a real domain type needs them; no generic base repository is planned.

## Dependency injection

Repository interfaces are bound to concrete Eloquent repositories in `App\Providers\AppServiceProvider::register()` (or a dedicated `RepositoryServiceProvider` if bindings become numerous). Use explicit one-to-one bindings:

```php
$this->app->bind(
    ProductRepositoryInterface::class,
    EloquentProductRepository::class,
);
```

Services use constructor injection and never resolve dependencies from the container manually. `UserRepositoryInterface` is bound to `EloquentUserRepository` in `AppServiceProvider::register()` for Milestone 1. `AuthController` injects `AuthService`; the service injects the repository interface.

## Transaction and concurrency policy

- Use `DB::transaction()` at the service boundary for checkout/cancellation.
- Acquire pessimistic `FOR UPDATE` locks through repositories.
- Sort multiple product IDs ascending before locking to reduce deadlock cycles.
- Keep transactions short; no HTTP calls, email, or expensive unrelated work inside them.
- Enforce stock with locked validation plus a database `stock_quantity >= 0` check constraint.
- Enforce promotion use by locking the promotion before reading global/customer ledger counts; unique `order_id` prevents duplicate usage per order. The promotion lock serializes first-use customers without a customer usage row.
- Make cancellation restoration observable through `inventory_restored_at`, updated while the order is locked.

## Security model

- HTTPS is mandatory outside local development.
- Sanctum tokens are hashed at rest by the framework and abilities may be introduced when multiple client roles exist.
- Authentication endpoints receive rate limits; password hashing uses Laravel's configured secure hasher.
- Policies and owner-scoped repository queries both guard customer resources (defense in depth). CartService explicitly authorizes view/update through CartPolicy for its authenticated User; item queries are then scoped through that customer's cart, without unscoped route binding.
- Return `404` for another customer's resource under the non-enumeration proposal.
- Validate all sort/filter names against allow-lists; never pass raw client column names to SQL.
- Do not log credentials, bearer tokens, full request bodies, or secret environment values.
- `.env` and generated credentials stay outside Git.

## Architectural decisions and trade-offs

| Decision | Reason | Trade-off |
|---|---|---|
| Modular monolith | Simple deployment and local ACID transactions | Domains cannot scale independently; not needed yet |
| Service + Repository for major domains | Required separation and test seams | More classes than direct Eloquent usage |
| PostgreSQL row locks | Strong, understandable concurrency control | Contended rows serialize and require careful lock order |
| Integer minor-unit money | Exact arithmetic and portable JSON | Currency exponent must be known/configured |
| One promotion per order | Deterministic MVP calculation | No stacking/combinability rules |
| Historical order snapshots | Auditability after catalogue changes | Intentional duplicated data |
| No stock reservation in cart | Avoids expiry/release subsystem | Checkout can reject a formerly valid cart |
| No generic repository | Keeps contracts business-oriented | Some query mechanics may repeat |

## Deployment and operations boundary

Compose is a development/test convenience, not a production topology. Production must supply managed secrets, TLS termination, backups, connection sizing, centralized logs, health probes, and a migration release procedure.

## Milestone 1 authentication decisions

- `AuthService` owns registration plus token issuance transactions and login hash-upgrade plus token-issuance transactions. User persistence and lookups belong to `EloquentUserRepository`; token creation and current-token deletion use native Sanctum model facilities directly in the service. No token repository or generic base repository is introduced.
- Form Requests normalize email and validate input. The User email mutator ensures Eloquent writes are canonical; the existing unique email constraint handles concurrent registrations. Passwords are explicitly hashed by the service and retain the model's hashed cast as a defense for other Eloquent writes.
- `AuthenticationResult` carries the User and Sanctum's native `NewAccessToken` to the HTTP layer. UserResource exposes only ID, name, normalized email, and timestamps. Reading `/me` uses the already authenticated user and requires no redundant repository query.
- Transport-independent credential/duplicate-email exceptions are mapped centrally in `bootstrap/app.php`. Laravel handles validation, authentication, and HTTP status/header generation; the response callback supplies the documented envelope and safe messages.
- `ApiRequestContext` applies to API paths before route middleware, including unknown routes. It generates a request ID for headers, errors, and Laravel log context and marks API responses non-cacheable. It never records request bodies or tokens.
- Sanctum is configured for bearer tokens only. Tokens keep native no-expiration defaults and all abilities for the single customer role; logout affects only the current token. Verification email delivery, password recovery, and role management are outside this milestone.

## Review findings and later milestone gates

The existing API proposal used DELETE logout; the explicit Milestone 1 contract supersedes it with POST. No future commerce behavior was changed. Discovery and business-rule documents still label several decisions as proposals, so they must be confirmed before the relevant domain implementation:

- Integer minor units and basis-point percentages are retained. Milestone 4 supersedes the round-down proposal with deterministic half-up rounding; catalogue currency remains configured per deployment.
- Milestone 5 supersedes the original lifecycle proposal with initial status `placed`. Later transitions and cancellation eligibility remain unresolved for Milestone 6.
- Cancellation retaining usage remains a proposal. Trimmed uppercase codes and database-enforced canonical uniqueness are confirmed in Milestone 4.
- One customer-owned cart, no stock reservation, early cart stock checks, and locked checkout revalidation are consistent.
- Checkout and cancellation services own their transactions. The documented relative resource lock order must be preserved when implemented. A missing per-customer usage row cannot itself be row-locked; the future implementation must define serialization, using the promotion lock or a concrete lockable aggregate.
- Milestone 5 implements optional `Idempotency-Key`, unique `(user_id, idempotency_key)`, 201 creation/200 replay, and permanent original-order replay even with a new cart. No body parameters are supported. See `11-checkout.md`.
- BR-X05 previously said bearer tokens are never returned; auth necessarily returns a newly issued token once. The rule now distinguishes issuance responses from profile/error/log disclosure.

The review findings above concern the Milestone 1 scope. Milestone 2 adds only the product catalogue components described below; checkout and order code remain unimplemented.


## Milestone 2 catalogue decisions

- `ProductController` injects `ProductService`, accepts `ProductQueryRequest` for listing, and returns `ProductResource`/a native paginated resource collection. Detail IDs deliberately go through the service/repository rather than implicit Eloquent binding, preserving the approved flow.
- A readonly `ProductQuery` DTO carries search, integer price bounds, nullable availability, sort/direction, and explicit page/page size. Form Request validation uses query parameters only; it creates the DTO after validation and exact cross-field range comparison.
- `ProductService::listProducts()` supplies `ProductStatus::Active` to `ProductRepositoryInterface::paginate(ProductQuery, ProductStatus)`. `getProduct(string)` validates bigint-compatible positive IDs, invokes `findById(int)`, and rejects missing/inactive products with transport-independent `ModelNotFoundException`; the existing renderer maps it to 404.
- `EloquentProductRepository` builds bound queries, maps the public sort allow-list to fixed SQL columns, escapes search wildcards, and applies deterministic secondary ID ordering. It independently rejects unchecked sort/direction values from non-HTTP callers. The container binds the product repository interface alongside the unchanged user repository.
- `Product` has explicit fillable fields, integer price/stock casts, a backed status enum, and trimmed/uppercase SKU normalization. There are no speculative relations, admin routes, global visibility scopes, inventory mutations, or read transactions.
- The approved database field is `price_minor`; public `price` is an integer money object. A single configured deployment currency defaults to USD. Native PostgreSQL identity and time-zone-aware timestamps follow the database design; existing framework migrations are unchanged.
- Static PostgreSQL DDL is limited to migration CHECKs and normalized-SKU uniqueness. Query indexes support public ordering; substring-search optimization awaits measured need.
- Service tests use repository mocks to isolate visibility rules, while API/repository/integrity/seeder tests exercise the dedicated real PostgreSQL test database. The standalone product seeder preserves existing sample and unrelated records and never calls the customer seeder.


## Milestone 3 shopping cart decisions

- `CartController` injects `CartService` and handles only validated input/resources/statuses. `AddCartItemRequest` and `UpdateCartItemRequest` require actual positive JSON integers. Service methods are `getCart(User)`, `addItem(User, productId, quantity)`, `updateItem(User, itemId, quantity)`, and `removeItem(User, itemId)`. HTTP identity always comes from Sanctum; extra ownership/money keys cannot affect writes. Auto-discovered CartPolicy guards view/update with 404 denials; item add/update/delete all modify the owner cart and use its update ability.
- `CartService` depends on `CartRepositoryInterface` and `ProductRepositoryInterface`. It owns ownership resolution, positive quantity validation for internal callers, active/stock checks, additive versus replacement quantities, exact current-price totals, and availability reasons. `CartView`/`CartLine` DTOs carry calculated values to API Resources without query/business logic in controllers/resources.
- `EloquentCartRepository` owns `findForUser`, `lockForUser`, `createOrLockForUser`, cart-scoped `findItem`/`findItemByProduct`, `createItem`, `updateQuantity`, `deleteItem`, and eager `loadItems`. The product repository adds `findByIdForUpdate`; catalogue reads keep their existing unlocked queries. Cart relations are added to User/Product and factories support real PostgreSQL fixtures.
- Every mutation has one service-owned `DB::transaction(..., attempts: 3)` boundary, including response estimate calculation for POST/PATCH. Failure rolls back first-cart creation, item persistence, and calculation-dependent updates. DELETE needs no product validation or totals and always remains available for owned unavailable/overflowing lines. No inventory write exists.
- Lock the customer's cart before any dependent product/item work. POST/PATCH take `FOR UPDATE` on only the affected product after the cart lock, then persist the item. Cart locking serializes all supported item mutations without redundant item row locks. Future checkout must preserve **Cart → Products (ascending ID) → Promotion → Customer usage**; cart mutations lock one product, so no product sorting is necessary here.
- First-cart creation initially looks up/locks an existing owner cart. If absent, PostgreSQL `INSERT ... ON CONFLICT DO NOTHING` relies on unique `user_id`, then a separate `SELECT ... FOR UPDATE` locks the winning row. Under default Read Committed, a competing insert waits for the winner and the subsequent statement sees its commit. Catching a unique violation inside a PostgreSQL transaction would leave it aborted; conflict-aware insertion avoids that problem. See [PostgreSQL 17 isolation documentation](https://www.postgresql.org/docs/17/transaction-iso.html).
- CHECK/FK/unique failures and exhausted serialization/deadlock/lock-not-available errors are caught outside the rolled-back transaction and become safe 409 `CART_CONFLICT`. Laravel retries detected concurrency errors up to three attempts; unexpected errors retain safe 500 behavior. No Redis/distributed lock or dependency change is introduced.
- GET uses eager `items.product` (one query for an absent cart, up to three for a populated cart), no writes, and no locks/transaction. All line/subtotal arithmetic is bounded integer arithmetic; overflow raises `CART_TOTAL_TOO_LARGE` before PHP can promote it to floating point. Unavailable lines remain with current prices and availability feedback.

Trade-offs: a popular product's cart mutations briefly serialize across customers; these locks keep eligibility coherent with stock/status changes but reserve no inventory. GET and unmodified products in mutation responses are current estimates, not cross-request price/stock guarantees. No load benchmark or pagination of cart lines is introduced. All future cart writers/checkout must participate in the cart-lock convention. Constraint/locking behavior is PostgreSQL-specific; concurrency tests prove overlapping requests on this database rather than portability to SQLite. Cart timestamps describe row creation/persistence; item timestamps describe item changes, and no extra parent timestamp write is made for every item mutation.

## Milestone 4 promotion decisions

- `CartPromotionController` delegates to `CartPromotionService`; all services depend on repository interfaces for database access. `PromotionService` resolves codes and returns `PromotionEligibilityResult`. `PromotionCalculator` is pure and returns `DiscountCalculationResult`.
- Existing cart arithmetic moves unchanged into `CartPricingService`, shared by CartService and CartPromotionService. Resources serialize DTO/model allow-lists only.
- `PromotionRepositoryInterface` exposes `findByCode(string $normalizedCode): ?Promotion` and `redemptionCounts(Promotion, int $customerId): array{global: int, customer: int}`. Bound equality and one PostgreSQL FILTER aggregate give global/customer counts the same statement snapshot. No redemption write or unused future locking API is added.
- `CartRepositoryInterface` adds `setPromotion(Cart, ?Promotion): void`; `findForUser`/`loadItems` eagerly include the promotion. Relations connect carts, promotions, customers, and ledger records.
- Apply/remove use one service transaction with the existing three-attempt concurrency retry and safe conflict mapping. They lock the owner cart, authorize through CartPolicy, read products without product locks, and persist only the selection. FK checks may acquire promotion key-share locks after the cart; no subsequent product lock is taken. Item mutations retain Cart → affected Product and only read promotion data for estimates.
- Failed applications preserve the previous code. Removal needs no prices or eligibility and supports empty/unavailable/overflowing carts. GET writes nothing and takes no locks/transaction; invalid selections return a reason and zero discount.
- Quotient/remainder percentage arithmetic rounds half-up before cap/subtotal clamps. No float intermediate is created. Subtotal overflow retains the existing 409/rollback contract.
- `promotion_redemptions` is the usage source of truth. Unique UUID `redemption_key` prepares replay deduplication; no counter, write endpoint, fabricated order ID, or placeholder order exists. Factories supply test records.
- Future checkout must add a unique order FK, persist a stable redemption key for the successful order/workflow, and reuse it on retries. Lock Cart → Products ascending → Promotion → Customer ledger; count under the promotion lock and insert redemption in the same transaction as order snapshots/inventory/cart clearing. The promotion lock serializes first use even when no customer ledger row exists. All writers must obey this protocol; uniqueness alone cannot enforce usage limits.

Known limits: estimates use Read Committed and prices/status/usage can change immediately after reading. No coupon or inventory reservation occurs; multiple customers may attach the last available use. Indexed counts are proportional to ledger history; no performance benchmark or cache/counter is claimed. Selection contention is tested; checkout overuse prevention, replay handling, order linkage, and exactly-once redemption workflows remain unimplemented/unverified. Account deletion with ledger history is restricted pending retention/privacy decisions.

## Milestone 5 checkout decisions

CheckoutController handles Sanctum context, CheckoutRequest header validation, OrderResource, and 201/200 status. CheckoutService owns the complete transaction and coordinates Cart/Product/Promotion/Order repository interfaces. CheckoutResult carries the persisted order and replay flag. Repositories own queries, row locks, conditional stock updates, order/item insertion, cart clearing, and order-linked redemption insertion; they do not commit the workflow.

Locked products replace each cart item's product relation before the shared CartPricingService calculation. Selected promotions are read under FOR UPDATE before the existing PromotionService eligibility and PromotionCalculator logic runs. The promotion lock is the serialization point for both global/customer ledger counts; no lockable customer usage aggregate is introduced. A UUID is generated once per checkout invocation and reused across transaction retries; successful replay returns the stored order without another insertion.

Cart rows remain after checkout so all supported mutations/replays share the same serialization point. Customer-scoped keys have database uniqueness and permanent replay semantics; body parameters are ignored so no request fingerprint is needed. OrderResources serialize stored snapshots only, and initial responses reload persisted values to match replay timestamps. PostgreSQL Read Committed is required. The four real process/barrier tests and two injected PostgreSQL deadlock tests are documented in `11-checkout.md`.

Earlier milestone sections above preserve the design state at their delivery; their references to future checkout are superseded by this section. Milestone 6 cancellation is implemented below.


## Milestone 6 order management decisions

OrderController delegates query validation to OrderQueryRequest and list/detail/cancellation to OrderService. Existing OrderResource/OrderItemResource serialize historical snapshots; list pagination never loads items or live products/promotions. OrderPolicy is auto-discovered and denies foreign owners as 404. Every repository lookup is ownership-scoped; policy checks add defense if a repository supplies the wrong owner.

OrderRepositoryInterface / EloquentOrderRepository add `paginateForUser`, `findForUser`, `lockForUser`, and `markCancelled`. Repository locking, eager loading, ordering, and writes remain persistence concerns. ProductRepositoryInterface adds `restoreStock`, a positive-quantity, conditional atomic increment bounded by signed bigint. It neither chooses order states nor coordinates cancellation.

OrderService owns state rules, inventory overflow checks, and `DB::transaction(..., attempts: 3)`. Cancellation takes Order → Products ascending ID, restores snapshots, and updates status/timestamps before committing. It takes no cart/promotion locks and touches no ledger. A cancelled row returns immediately without inventory writes. No CancellationResult DTO is needed because first and repeated cancellations have the same 200/resource contract. Existing CheckoutResult remains unchanged.

The additive migration enforces placed/null markers versus cancelled/equal non-null markers, adds the owner/creation-date/ID index, and preserves earlier records and FKs. PostgreSQL constraints/locks remain the integrity boundary; no application locks, Redis, cache, or external effects are introduced. See `12-order-management.md` for trade-offs, rollback refusal, failure evidence, and concurrency results.

## Bonus Milestone 7A — Product and promotion administration

`AdminProductController` and `AdminPromotionController` delegate to `ProductAdministrationService` and `PromotionAdministrationService`, which depend on the existing Product/Promotion repository interfaces. Eloquent implementations add creation, partial updates and admin reads, reusing product locks and catalogue query logic. `ProductResource` is reused; `AdminPromotionResource` exposes editable fields and aggregate usage only. Six admin Form Requests validate the boundary. Public catalogue visibility and checkout/calculator behavior are unchanged.

`users.is_admin` is a non-null boolean defaulting to false, cast and hidden on User, and excluded from fillable attributes. `ProductPolicy` and `PromotionPolicy` are auto-discovered. Routes authorize class-level viewAny/view/create/update abilities before validation or lookup; services authorize again for direct callers. These policies govern administration only; public catalogue reads do not invoke them. Wildcard token abilities do not confer administration. There is no broad Gate::before administrator bypass of customer ownership policies.

Product updates take one Product lock; promotion updates take one Promotion lock. Neither acquires a Cart, Order or another domain row afterward. Existing Cart → sorted Products → Promotion checkout and Order → sorted Products cancellation remain intact. Each mutation owns a short PostgreSQL transaction with up to three detected-concurrency attempts. Duplicate normalized SKU/code failures are mapped after rollback to field-specific 422 errors; exhausted contention becomes a safe 409.

Stock adjustment is a signed delta applied to the locked current stock, bounded before integer addition; absolute stock replacement is supported only at product creation. Promotion cross-field validation merges PATCH fields with the locked retained values. Supplied non-null usage limits cannot be below total consumed usage or the largest individual customer's consumption. Equality is allowed and exhausts further eligibility; null removes a limit. The shared promotion lock protects the count/check/update against checkout's count/insert/commit. No historical order or redemption writer is added.

`admin:grant` delegates to `AdministratorProvisioningService` → User repository. It requires APP_ENV=local and an existing registered email; it does not create an account, change a password, or issue a token. Production provisioning and privilege revocation workflows are outside this development-only command. See [14-admin-management.md](14-admin-management.md) for API examples, security review, verified contention, and future cache invalidation requirements.
