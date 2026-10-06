# 07 — API Contracts

## General conventions

- Base path: `/api` as required (no version prefix in the assessment contract).
- Media type: `application/json`; clients send `Accept: application/json`.
- Timestamps: ISO 8601 UTC strings.
- Money: objects with integer `amount_minor` and 3-letter `currency`; requests never submit authoritative totals.
- Collection page size defaults to 15 and is capped at 100 (proposed).
- Protected routes use `Authorization: Bearer <token>`.

## Response envelopes

Single resource:

```json
{"data":{"id":1}}
```

Paginated collection:

```json
{
  "data": [],
  "meta": {"current_page":1,"per_page":15,"last_page":1,"total":0},
  "links": {"first":"...","last":"...","prev":null,"next":null}
}
```

Error:

```json
{
  "error": {
    "code": "INSUFFICIENT_STOCK",
    "message": "The requested quantity is no longer available.",
    "details": {"product_id": 42, "available": 2},
    "request_id": "generated-uuid"
  }
}
```

Validation uses code `VALIDATION_FAILED`, message `The given data was invalid.`, and `details.fields` keyed by field name. API errors are JSON even without an Accept header. API responses include a generated `X-Request-ID` matching `error.request_id` and `Cache-Control: no-store, private`. Unexpected errors use a generic message and omit traces, SQL, and internal exception details, even when debug is enabled; Laravel still reports them server-side.

## Endpoints

### Foundation

| Method/path | Auth | Success |
|---|---|---|
| `GET /api/health` | No | `200`, `{"data":{"status":"ok"}}` |

### Authentication (implemented in Milestone 1)

| Method/path | Body | Success |
|---|---|---|
| `POST /api/auth/register` | `name`, `email`, `password`, `password_confirmation`, optional `device_name` | `201` user + token |
| `POST /api/auth/login` | `email`, `password`, optional `device_name` | `200` user + token |
| `POST /api/auth/logout` | none | `204` |
| `GET /api/auth/me` | none | `200` current customer |

Registration/login response:

```json
{
  "data": {
    "user": {"id":1,"name":"Example Customer","email":"customer@example.com","created_at":"2026-10-06T12:00:00.000000Z","updated_at":"2026-10-06T12:00:00.000000Z"},
    "token": "<new-sanctum-token>",
    "token_type": "Bearer"
  }
}
```

`GET /api/auth/me` returns `{"data":{...public user fields...}}`; logout returns no body. Profile fields are exactly `id`, `name`, `email`, `created_at`, and `updated_at`. Passwords, password hashes, remember tokens, verification state, and token records are excluded.

Emails are trimmed/lowercased before validation, storage, and lookup. Registration requires a nonblank name of up to 255 characters, valid email of up to 255 characters unique after normalization, and confirmed password of at least 12 characters with mixed case, numbers, and symbols. Passwords are limited to 72 bytes and may not contain null bytes. Login accepts existing passwords without applying registration strength rules. Optional `device_name` is a string of up to 100 characters, default `api-client`.

Registration permits 5 attempts/minute/IP. Login permits 5 attempts/minute/normalized-email-and-IP and 30 attempts/minute/IP. Successes and failures count, and throttled responses include `Retry-After`. Duplicate emails return 422 `VALIDATION_FAILED` with `details.fields.email`, including races detected by the database constraint. Invalid credentials return 401 `UNAUTHENTICATED` with `The provided credentials are incorrect.` Missing/invalid/revoked tokens return 401 with `Unauthenticated.`

Authentication uses bearer tokens only, without web session fallback. Tokens retain native Sanctum no-expiration defaults and `*` abilities for the customer role. Logout deletes only the authenticating token; other tokens remain valid. Unverified accounts may authenticate; verification and password recovery are outside Milestone 1. POST logout supersedes the foundation's DELETE proposal per the approved milestone request.

### Products

| Method/path | Query | Success |
|---|---|---|
| `GET /api/products` | `search`, `min_price`, `max_price`, `available`, `sort`, `direction`, `page`, `per_page` | `200` paginated products |
| `GET /api/products/{id}` | none | `200` product |

Allowed sorts are proposed as `name`, `price`, and `created_at`; direction is `asc`/`desc`. Price filter values are integer minor units. Inactive/missing products return `404`.

### Cart

| Method/path | Body | Success |
|---|---|---|
| `GET /api/cart` | none | `200` cart estimate |
| `POST /api/cart/items` | `product_id`, `quantity` | `201` cart |
| `PATCH /api/cart/items/{id}` | `quantity` | `200` cart |
| `DELETE /api/cart/items/{id}` | none | `204` |
| `PUT /api/cart/promotion` | `code` | `200` cart estimate (proposed) |
| `DELETE /api/cart/promotion` | none | `204` (proposed) |

Cart line `{id}` is always resolved inside the authenticated customer's cart scope.

### Checkout

| Method/path | Body | Success |
|---|---|---|
| `POST /api/checkout` | optional `idempotency_key` (proposal; not yet required) | `201` order |

The server ignores/rejects client price and total fields. A formal idempotency-key requirement remains open; database correctness protects inventory, but retry-safe order creation is materially better with an idempotency key.

### Orders

| Method/path | Query/body | Success |
|---|---|---|
| `GET /api/orders` | `page`, `per_page` | `200` paginated own orders |
| `GET /api/orders/{id}` | none | `200` own order with items |
| `POST /api/orders/{id}/cancel` | none | `200` cancelled order |

## Status and error mapping

| HTTP | Stable code | Use |
|---|---|---|
| `400` | `MALFORMED_REQUEST` | Invalid JSON or structurally unreadable request |
| `401` | `UNAUTHENTICATED` | Missing/invalid token or bad credentials |
| `403` | `FORBIDDEN` | Action is forbidden |
| `405` | `METHOD_NOT_ALLOWED` | Method is unsupported |
| `409` | `CONFLICT` | Generic HTTP conflict |
| `404` | `RESOURCE_NOT_FOUND` | Missing/inactive or non-owned resource |
| `409` | `INSUFFICIENT_STOCK` | Quantity cannot be fulfilled |
| `409` | `EMPTY_CART` | Checkout has no items |
| `409` | `INVALID_ORDER_STATUS` | Status transition/cancellation is not allowed |
| `409` | `PROMOTION_USAGE_LIMIT_REACHED` | Global or customer limit exhausted |
| `422` | `VALIDATION_FAILED` | Request field validation |
| `422` | `INVALID_PROMOTION` | Missing/inactive/ineligible code |
| `422` | `PROMOTION_EXPIRED` | Outside validity interval |
| `422` | `PROMOTION_MINIMUM_NOT_MET` | Subtotal below threshold |
| `429` | `TOO_MANY_REQUESTS` | Rate limit exceeded |
| `500` | `INTERNAL_ERROR` | Unexpected failure; details not exposed |
| `503` | `SERVICE_UNAVAILABLE` | Optional readiness/dependency failure |

Authentication/error mappings above are implemented. Commerce-specific error codes and distinctions between `409` and `422` remain proposed and must be frozen before those domain milestones; no commerce exception classes or endpoints exist yet.

## Resource outline

A product exposes `id`, `name`, `sku`, `description`, money-valued `price`, `stock_quantity` (or a future availability abstraction), `status`, and timestamps. An order exposes status, currency, subtotal, discount, total, optional promotion-code snapshot, cancellation timestamp, timestamps, and item snapshots. Passwords, password hashes, internal lock/counter fields, and unrelated foreign keys are never exposed. A new token is exposed only in its registration/login issuance response.
