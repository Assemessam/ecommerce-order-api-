# E-Commerce Order & Promotion API

Production-oriented backend assessment for product discovery, customer carts, promotions, transactional checkout, inventory safety, orders, and cancellation. This repository contains the Laravel foundation, **Milestone 1: Authentication & API Foundation**, **Milestone 2: Product Catalogue**, **Milestone 3: Shopping Cart**, and **Milestone 4: Promotions & Discount Engine**. Checkout, orders, and cancellation remain unimplemented.

## Technology stack

- PHP 8.3+ (Docker image uses PHP 8.5)
- Laravel 13
- PostgreSQL 17
- Laravel Sanctum
- Eloquent ORM
- Pest 4
- Composer 2
- Docker Compose

## Requirements

Recommended: Docker Engine with Compose v2+ and Git. Native execution additionally requires PHP 8.3+, Composer 2, and PHP extensions including `pdo_pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, and `intl`.

## Installation

```bash
git clone <repository-url> ecommerce-order-api
cd ecommerce-order-api
cp .env.example .env
docker compose build api
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose up -d postgres
docker compose run --rm api php artisan migrate
```

The example credentials are for local development only. Use externally managed secrets in deployed environments.

## Database setup

Compose starts PostgreSQL with development database `ecommerce_order_api`, isolated test database `ecommerce_order_api_test`, user `ecommerce`, and the local-only password in `compose.yaml`. Laravel connects to host `postgres` from the Compose network. The project database has its own Compose network and `ecommerce-order-api_postgres_data` volume, with no published PostgreSQL host port. It coexists with `postgres-local` on host port 5432; do not stop, reset, or modify unrelated containers. Development and test database names are distinct. To reset disposable local data:

```bash
docker compose down -v
docker compose up -d postgres
docker compose run --rm api php artisan migrate
```

The `-v` command deletes the local Compose database volume; do not use it on data you need to retain.

## Run the API

```bash
docker compose up --build
```

The API is available at `http://localhost:8091` by default. Override `APP_PORT` in `.env` if needed. Verify it with:

```bash
curl -H 'Accept: application/json' http://localhost:8091/api/health
```

Expected response:

```json
{"data":{"status":"ok"}}
```

## Run tests and quality checks

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/AuthControllerTest.php
docker compose exec -T api php artisan test --compact
docker compose exec -T api vendor/bin/pint --dirty --format agent
docker compose exec -T api composer validate --strict
docker compose exec -T api composer audit
```

Database-sensitive tests use the dedicated PostgreSQL database `ecommerce_order_api_test` and `LazilyRefreshDatabase`. PHPUnit overrides both environment and server variables so Compose development settings cannot redirect tests to `ecommerce_order_api`. The base test case refuses an unexpected database target before migrations or refresh operations. No static analyzer is currently installed or configured.

## Architecture

Major domains use:

```text
Controller → Service → Repository interface → Eloquent repository → Eloquent model → PostgreSQL
```

Controllers stay HTTP-focused, services own business rules and workflow transactions, and repositories own persistence/query mechanics. Checkout and cancellation use deterministic row-lock ordering. See [`docs/05-architecture.md`](docs/05-architecture.md) and the other numbered design documents.

## Current status

Implemented:

- Laravel 13 application foundation.
- Sanctum package, API routing, and token migration.
- Pest test framework.
- PostgreSQL-oriented environment example and Docker Compose runtime.
- JSON `GET /api/health` endpoint and feature test.
- Service/Repository directory conventions and complete planning documents.
- Registration, login, current customer, and current-token logout.
- Form Requests, UserResource, AuthService, and a container-bound user repository.
- JSON error envelopes, request IDs, password policy, and authentication rate limiting.
- Public active-product list/detail, literal name search, minor-unit price filters, availability, allow-listed sorting, and bounded pagination.
- Product Service/Repository, PostgreSQL integrity constraints, factory, repeatable sample seeder, and catalogue tests.
- Authenticated owner-scoped cart reads/adds/updates/deletes, exact current-price estimates, availability feedback, PostgreSQL constraints, atomic mutations, and real concurrent HTTP request tests.
- Normalized percentage/fixed codes, PostgreSQL integrity constraints, reusable integer calculation, ledger-based eligibility, cart promotion application/removal, fresh eligibility on estimates, factories/seeder, and independent-process contention tests.

Not implemented yet:

- Checkout, orders, inventory deduction, and cancellation.

Milestone 4 is complete; stop here for review. The next planned milestone is **Milestone 5: Checkout**, which has not started. Remaining business proposals are recorded in [`docs/01-business-discovery.md`](docs/01-business-discovery.md); no checkout or order decisions were changed.

## Product catalogue

Both endpoints are public: `GET /api/products` returns `data`, `links`, and Laravel pagination `meta`; `GET /api/products/{id}` returns one resource under `data`. Missing, inactive, malformed, and overflowing IDs return the existing JSON 404 envelope. Active products with zero stock remain visible.

Run the additive migration and, optionally, seed only catalogue samples:

```bash
docker compose exec -T api php artisan migrate --no-interaction
docker compose exec -T api php artisan db:seed --class=ProductSeeder --no-interaction
```

Use `ProductSeeder` directly to avoid the existing customer seeder. It inserts six demo SKUs covering both statuses and stock states, skips existing demo records, and neither resets prices/inventory nor changes customers. Repeated runs do not duplicate products.

| Query | Contract |
|---|---|
| `search` | Trimmed, case-insensitive literal substring of name; maximum 100 characters; empty means no search |
| `min_price`, `max_price` | Inclusive integer minor units, 0..9223372036854775807; minimum must not exceed maximum |
| `available` | `1`/`true`: stock > 0; `0`/`false`: stock = 0; omitted: both; inactive products always excluded |
| `sort` | `name`, `price`, `created_at`; default `created_at` |
| `direction` | `asc`/`desc`; default `desc`; ID breaks ties in the same direction |
| `page` | Integer 1..2147483647; default 1 |
| `per_page` | Integer 1..100; default 15 |

Invalid query values return 422 `VALIDATION_FAILED` with `error.details.fields`. Supplied empty values are invalid except for `search`. Unknown query keys are ignored and omitted from pagination links. GET request bodies do not override query parameters. Links preserve validated filters. Sorting uses PostgreSQL's configured collation; search uses `ILIKE` with escaped `%`, `_`, and backslash.

Prices are stored as signed `bigint` `price_minor`, preserving the approved database design rather than adding a duplicate `price` column. Resources expose `"price":{"amount_minor":1899,"currency":"USD"}`; `1899` means 18.99 in a currency with two minor-unit digits. Filters never accept major-unit decimals or use floating-point conversion. `CATALOGUE_CURRENCY` defaults to `USD` through `config/catalogue.php`; it labels the single deployment currency and performs no conversion.

Public product fields are exactly `id`, `name`, `sku`, nullable `description`, money-valued `price`, `stock_quantity`, `status`, `created_at`, and `updated_at`. SKUs are trimmed/uppercased by the model, and a PostgreSQL unique index on `lower(btrim(sku))` prevents case/space duplicate imports. Database checks forbid negative price/stock, unsupported status, and blank SKU. Composite indexes support status-scoped ID, price, name, and created-date ordering. Substring search has no specialized index until measured need justifies one.

The original FR-P03 requirement for name/SKU/description search is preserved in `docs/02-requirements.md`; this milestone explicitly implements name search only. See `docs/07-api-contracts.md` for response examples and the full contract.

Focused catalogue tests:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/ProductControllerTest.php tests/Feature/Models/ProductTest.php tests/Feature/Repositories/Eloquent/EloquentProductRepositoryTest.php tests/Feature/Services/Product/ProductServiceTest.php tests/Feature/Database/Seeders/ProductSeederTest.php
```

## Authentication

| Method | Endpoint | Authentication | Success |
|---|---|---|---|
| POST | `/api/auth/register` | Public | 201, customer and token |
| POST | `/api/auth/login` | Public | 200, customer and token |
| POST | `/api/auth/logout` | Bearer token | 204, empty body |
| GET | `/api/auth/me` | Bearer token | 200, public customer profile |

Registration requires `name`, `email`, `password`, and matching `password_confirmation`. Registration and login accept optional `device_name` (up to 100 characters, default `api-client`). Emails are trimmed and lowercased before validation, storage, and lookup. Passwords require at least 12 characters, uppercase and lowercase letters, a number, and a symbol. The bcrypt-compatible limit is 72 bytes; null bytes are rejected. Login validates credentials without applying the registration strength policy to existing passwords.

```bash
curl -X POST http://localhost:8091/api/auth/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Example Customer","email":"customer@example.com","password":"StrongPassword123!","password_confirmation":"StrongPassword123!","device_name":"Postman"}'

curl -X POST http://localhost:8091/api/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"customer@example.com","password":"StrongPassword123!","device_name":"Postman"}'

curl http://localhost:8091/api/auth/me \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'

curl -X POST http://localhost:8091/api/auth/logout \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```

Registration/login return `{"data":{"user":{"id":1,"name":"Example Customer","email":"customer@example.com","created_at":"...","updated_at":"..."},"token":"<token>","token_type":"Bearer"}}`. Save `data.token` securely and choose **Bearer Token** authorization in Postman. The plain token is returned only when issued; `/me` returns only `data` containing the public profile. Logout revokes the current token, preserving other devices and customers. Reusing that token returns 401.

Security decisions:

- Sanctum authenticates bearer tokens only; web sessions cannot authenticate these endpoints. No custom token implementation or session login is introduced.
- Passwords always pass through Laravel's configured hasher, including input resembling a hash; Sanctum hashes tokens at rest. Password hashes and remember tokens remain hidden, and resources allow-list public attributes.
- Registration and initial token creation share a service-owned transaction. Concurrent unique-email conflicts return the same 422 field error as ordinary duplicate validation. No new migrations are required.
- Login verifies the password and upgrades an outdated hash through the repository. Each successful login creates an independent token. Unverified accounts may authenticate; email verification is outside this milestone.
- Tokens retain Sanctum's default no-expiration policy and `*` abilities for the current single-customer role. Logout revokes them explicitly. Expiration and finer abilities can be reviewed separately.
- Registration permits 5 attempts/minute/IP. Login permits 5 attempts/minute/normalized-email-and-IP plus 30 attempts/minute/IP. Invalid and successful attempts count; 429 responses preserve `Retry-After`. Development uses the configured shared database cache; tests use an isolated array cache.
- Never log request bodies, passwords, or bearer tokens. Use HTTPS outside local development and keep production `APP_DEBUG=false`. API responses use `Cache-Control: no-store, private`.

## API responses and errors

Successes use `data`; logout and cart item deletion use an empty 204. All `/api` failures use JSON even without an Accept header:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","details":{"fields":{"email":["The email field is required."]}},"request_id":"<generated-uuid>"}}
```

Invalid credentials and missing/invalid/revoked tokens return 401 with `UNAUTHENTICATED`. Invalid credentials always use “The provided credentials are incorrect.” Validation returns 422, forbidden requests 403, missing resources 404, conflicts 409, and throttled requests 429. Unexpected failures return generic 500 `INTERNAL_ERROR` without SQL, traces, or exception details, including when local debug is enabled. Laravel still reports unexpected exceptions server-side. A generated `X-Request-ID` accompanies API responses and the matching request ID is added to log context. See [`docs/07-api-contracts.md`](docs/07-api-contracts.md).


## Shopping cart

Apply the two additive cart migrations with `docker compose exec -T api php artisan migrate --no-interaction`. Cart factories provide isolated test fixtures; no cart sample seeder is needed for private, customer-created state.

All four endpoints require a Sanctum bearer token:

| Method | Endpoint | Success |
|---|---|---|
| GET | `/api/cart` | 200 cart estimate |
| POST | `/api/cart/items` | 201 cart, including merged additions |
| PATCH | `/api/cart/items/{id}` | 200 cart |
| DELETE | `/api/cart/items/{id}` | 204 empty body |

```bash
curl http://localhost:8091/api/cart -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
curl -X POST http://localhost:8091/api/cart/items \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'Authorization: Bearer <token>' \
  -d '{"product_id":1,"quantity":2}'
curl -X PATCH http://localhost:8091/api/cart/items/1 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H 'Authorization: Bearer <token>' \
  -d '{"quantity":3}'
curl -X DELETE http://localhost:8091/api/cart/items/1 \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```

PATCH/DELETE IDs identify cart lines, not products. POST increments an existing line; PATCH replaces its quantity. Bodies require actual positive JSON integers in the signed 64-bit range; strings, floats, booleans, zero, and negatives are rejected with 422. Ownership comes from the token; extra ownership, product-reassignment, price, and total fields are ignored. Missing/non-owned items return indistinguishable 404 errors.

A customer without a cart receives `{"data":{"id":null,"items":[],"subtotal":{"amount_minor":0,"currency":"USD"},"promotion":null,"promotion_eligibility":null,"estimated_discount":{"amount_minor":0,"currency":"USD"},"estimated_total":{"amount_minor":0,"currency":"USD"}}}` with no database write. Persisted empty carts retain their ID. Each line exposes `id`, nested public `product`, `quantity`, `unit_price`, `line_subtotal`, and `availability`; the cart preserves `id`, `items`, and `subtotal` and adds the promotion fields documented below. All prices and totals use the catalogue's configured currency and current server prices. See `docs/07-api-contracts.md` for full examples.

Unavailable lines remain in the estimate and subtotal: `availability.is_available=false`, `reason=inactive|out_of_stock|insufficient_stock`, and `available_quantity` is current stock. Newly adding/updating inactive products returns 409 `PRODUCT_INACTIVE`; insufficient stock returns 409 `INSUFFICIENT_STOCK`. DELETE is always allowed for an owned line, including unavailable products, and never restores stock. Cart additions neither reserve nor deduct inventory: different customers can independently cart quantities whose combined total exceeds stock. Checkout must revalidate and allocate inventory later.

Service-owned transactions lock Cart → the affected Product → persist item changes. PostgreSQL uniqueness plus `ON CONFLICT DO NOTHING` and a subsequent locked lookup safely creates the first cart. Locks serialize concurrent quantities; no Redis is used. Integrity/remaining lock conflicts return safe 409 `CART_CONFLICT`. Without a selected promotion, GET performs at most three cart/item/product queries with eager loading and is an unlocked estimate. Amounts exceeding signed bigint return 409 `CART_TOTAL_TOO_LARGE`, never rounded floats; failed mutations roll back. DELETE can recover a cart whose current prices overflow.

Run cart tests sequentially against the dedicated test database:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Http/Controllers/Api/CartControllerTest.php tests/Feature/Models/CartTest.php tests/Feature/Policies/CartPolicyTest.php tests/Feature/Services/Cart/CartServiceTest.php tests/Feature/Services/Cart/CartConcurrencyTest.php
```

Concurrency tests use separate PHP processes with authenticated HTTP kernel requests, independent PostgreSQL connections, committed fixtures, and a database lock barrier. They assert two active lock waiters before release, then verify first-cart uniqueness, merged quantities, and stock conflicts. They use `DatabaseMigrations`, not transaction-wrapped refreshes; do not run another suite concurrently on this same test database. See `docs/09-testing-strategy.md`.

## Promotions and discounts (Milestone 4)

Run the three additive migrations and optionally seed only promotion examples:

```bash
docker compose exec -T api php artisan migrate --no-interaction
docker compose exec -T api php artisan db:seed --class=PromotionSeeder --no-interaction
```

The seeder inserts missing SUMMER20, WELCOME10, SAVE15, LIMITED5, INACTIVE10, FUTURE10, and EXPIRED10 examples; it preserves existing promotions, customers, and products. SUMMER20 is 20% with a 10000-minor-unit minimum and 5000-minor-unit cap; SAVE15 is 1500 minor units; LIMITED5 is 500 minor units with global limit 5 and customer limit 1. Future/expired dates are relative to the first seed run and are not refreshed on subsequent runs.

| Method | Endpoint | Success |
|---|---|---|
| POST | `/api/cart/promotion` | 200 updated cart estimate |
| DELETE | `/api/cart/promotion` | 204 empty body, including repeated removal |

Both require a Sanctum bearer token. Send only `code`; ownership comes from the token. Codes are trimmed and uppercased: `summer20`, ` SUMMER20 `, and `SUMMER20` resolve identically. Canonical format is 1..64 ASCII letters/digits, hyphens, or underscores, beginning with a letter/digit. Invalid input returns 422 field errors. An eligible promotion replaces the current selection; any failed application preserves it.

```bash
curl -X POST http://localhost:8091/api/cart/promotion \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <token>' -d '{"code":"SUMMER20"}'

curl -X DELETE http://localhost:8091/api/cart/promotion \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```

Cart GET, item POST/PATCH, and promotion POST add `promotion`, `promotion_eligibility`, `estimated_discount`, and `estimated_total`. Without a selection, promotion/eligibility are null, discount is zero, and total equals subtotal. Invalid selections remain attached with `is_eligible=false`, a reason/message, and zero discount. Every estimate uses current prices and ledger counts. Empty carts or any inactive/out-of-stock/insufficient-stock line invalidate a selected discount; unavailable lines remain in the subtotal.

Fixed values use integer minor units; percentages use basis points (2000 = 20%). Percentage discounts round half-up, apply any positive cap, and never exceed subtotal. The calculator avoids full-subtotal multiplication and remains exact through signed bigint maximum. Minimum spend uses pre-discount subtotal. UTC validity uses inclusive `starts_at` and exclusive `expires_at`; null bounds are unbounded. Null usage limits mean unlimited; non-null limits are positive.

**Application is not redemption or reservation.** Inventory and usage stay unchanged. The `promotion_redemptions` ledger has restricted promotion/customer FKs, a unique UUID redemption key, discount snapshot, and redemption timestamp. No redemption endpoint, counter, order table, or fictional order reference exists. Only factory fixtures populate the ledger in this milestone. Future checkout must add a unique order FK, reuse a stable redemption key, lock Cart → Products (ascending ID) → Promotion → Customer usage, revalidate, and insert redemption in the same successful order/inventory transaction. Checkout-time limit enforcement and exactly-once workflow behavior are not implemented or verified.

Promotion writes lock the owner cart in one short service transaction, with bounded retries and safe 409 conflict handling. Product estimates take no product row locks. GET is unlocked; a selected promotion uses up to five domain queries (four eager-load queries plus one aggregate for limited codes), independent of line count. See [architecture](docs/05-architecture.md), [schema](docs/06-database-design.md), [API examples](docs/07-api-contracts.md), and [executed verification](docs/09-testing-strategy.md).

Run focused tests sequentially on the isolated PostgreSQL database:

```bash
docker compose exec -T api php artisan test --compact \
  tests/Unit/Services/Promotion \
  tests/Feature/Models/PromotionTest.php tests/Feature/Models/PromotionRedemptionTest.php \
  tests/Feature/Services/Promotion tests/Feature/Services/Cart/CartPromotionServiceTest.php \
  tests/Feature/Http/Controllers/Api/CartPromotionControllerTest.php \
  tests/Feature/Repositories/Eloquent/EloquentPromotionRepositoryTest.php \
  tests/Feature/Database/Seeders/PromotionSeederTest.php

docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php
```
