# E-Commerce Order & Promotion API

Production-oriented backend assessment for product discovery, customer carts, promotions, transactional checkout, inventory safety, orders, and cancellation. This repository contains the Laravel foundation and **Milestone 1: Authentication & API Foundation**. Commerce domains remain unimplemented.

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

Authentication tests use the dedicated PostgreSQL database `ecommerce_order_api_test` and `LazilyRefreshDatabase`. PHPUnit overrides both environment and server variables so Compose development settings cannot redirect tests to `ecommerce_order_api`. The base test case refuses an unexpected database target before migrations or refresh operations. No static analyzer is currently installed or configured.

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

Not implemented yet:

- Products, carts, promotions, checkout, orders, inventory deduction, and cancellation.

The next recommended milestone is **Milestone 2: Product catalogue**, after reviewing Milestone 1. Remaining business proposals are recorded in [`docs/01-business-discovery.md`](docs/01-business-discovery.md); no checkout or order decisions were changed.

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

Successes use `data`; logout uses an empty 204. All `/api` failures use JSON even without an Accept header:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","details":{"fields":{"email":["The email field is required."]}},"request_id":"<generated-uuid>"}}
```

Invalid credentials and missing/invalid/revoked tokens return 401 with `UNAUTHENTICATED`. Invalid credentials always use “The provided credentials are incorrect.” Validation returns 422, forbidden requests 403, missing resources 404, conflicts 409, and throttled requests 429. Unexpected failures return generic 500 `INTERNAL_ERROR` without SQL, traces, or exception details, including when local debug is enabled. Laravel still reports unexpected exceptions server-side. A generated `X-Request-ID` accompanies API responses and the matching request ID is added to log context. See [`docs/07-api-contracts.md`](docs/07-api-contracts.md).
