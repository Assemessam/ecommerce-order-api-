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
- Enforce promotion use by locking the promotion and serialized customer usage key/aggregate; a unique `(promotion_id, order_id)` usage record supports auditability.
- Make cancellation restoration observable through `inventory_restored_at`, updated while the order is locked.

## Security model

- HTTPS is mandatory outside local development.
- Sanctum tokens are hashed at rest by the framework and abilities may be introduced when multiple client roles exist.
- Authentication endpoints receive rate limits; password hashing uses Laravel's configured secure hasher.
- Policies and owner-scoped repository queries both guard customer resources (defense in depth).
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

- USD as the single currency, integer minor-unit money, basis-point percentage discounts, and round-down behavior remain consistent across documents.
- Proposed order statuses are pending, confirmed, processing, shipped, completed, and cancelled; cancellation is allowed only for pending/confirmed. The initial checkout status and transition authority are not yet specified.
- Cancellation retains promotion usage; coupon codes are trimmed and uppercased with case-insensitive uniqueness. Both remain marked as proposals.
- One customer-owned cart, no stock reservation, early cart stock checks, and locked checkout revalidation are consistent.
- Checkout and cancellation services own their transactions. The documented relative resource lock order must be preserved when implemented. A missing per-customer usage row cannot itself be row-locked; the future implementation must define serialization, using the promotion lock or a concrete lockable aggregate.
- Checkout idempotency remains optional in the proposed contract, and no persistence design exists for retry keys. Resolve the key transport, uniqueness, replay response, payload mismatch, and retention before checkout. Database inventory safety alone does not define safe replay semantics.
- BR-X05 previously said bearer tokens are never returned; auth necessarily returns a newly issued token once. The rule now distinguishes issuance responses from profile/error/log disclosure.

No additional business services, tables, checkout, or order code were introduced.
