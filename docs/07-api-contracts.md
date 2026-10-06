# 07 — API Contracts

## General conventions

- Base path: `/api` as required (no version prefix in the assessment contract).
- Media type: `application/json`; clients send `Accept: application/json`.
- Timestamps: ISO 8601 UTC strings.
- Money: objects with integer `amount_minor` and 3-letter `currency`; requests never submit authoritative totals.
- Product collection page size defaults to 15 and is capped at 100 (implemented); the same limits remain proposed for future collections.
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

### Products (implemented in Milestone 2; public, no token required)

| Method/path | Query | Success |
|---|---|---|
| `GET /api/products` | `search`, `min_price`, `max_price`, `available`, `sort`, `direction`, `page`, `per_page` | `200` paginated products |
| `GET /api/products/{id}` | none | `200` product |

| Parameter | Validation and behavior |
|---|---|
| `search` | Optional nullable string, trimmed, maximum 100 characters; empty/whitespace means no search; embedded null bytes rejected. Literal case-insensitive substring of **name only**; `%`, `_`, and backslash are escaped, values bound via `whereLike`/PostgreSQL `ILIKE`. |
| `min_price`, `max_price` | Optional inclusive signed-bigint-compatible integer minor units, 0..9223372036854775807. No decimals, exponent notation, arrays, negatives, or overflow. When both supplied, `min_price <= max_price`; comparison uses integers. |
| `available` | Optional `1`, `0`, `true`, `false` query strings. True means `stock_quantity > 0`; false means `stock_quantity = 0`; omitted includes both. |
| `sort` | `name`, `price`, `created_at`; default `created_at`. `price` maps to the fixed `price_minor` database column. |
| `direction` | `asc` or `desc`; default `desc`. Every sort uses secondary ID ordering in the same direction. Name order follows PostgreSQL collation. |
| `page` | Integer 1..2147483647, default 1. Beyond the last page returns empty `data` with the actual `total`. |
| `per_page` | Integer 1..100, default 15; oversized values are rejected with 422 rather than silently clamped. |

Supplied empty query values are invalid except `search`. Arrays and invalid types are rejected. Unknown query keys are ignored and cannot change visibility or database columns. GET bodies are ignored for catalogue query validation. Pagination links retain only validated parameters.

Both endpoints expose active products only. Active zero-stock products remain visible without an availability filter, including details; inactive details return the same 404 envelope as missing products. Non-numeric/zero/negative/overflow IDs return 404 without passing an invalid bigint to PostgreSQL. Default listing order is `created_at desc, id desc`. There is no administrative CRUD or stock write endpoint.

A detail response (the identical object appears in list `data`):

```json
{
  "data": {
    "id": 1,
    "name": "Insulated Travel Mug",
    "sku": "DEMO-MUG",
    "description": "Stainless steel mug for daily travel.",
    "price": {"amount_minor": 1899, "currency": "USD"},
    "stock_quantity": 40,
    "status": "active",
    "created_at": "2026-10-06T12:00:00.000000Z",
    "updated_at": "2026-10-06T12:00:00.000000Z"
  }
}
```

The public allow-list is exactly those nine fields; nullable description stays null. Internal `price_minor` is not exposed separately. `CATALOGUE_CURRENCY`/`config('catalogue.currency')` labels one deployment currency, default USD, without conversion or per-product currency fields. Filters use the same integer representation as `price.amount_minor`; `1899` means 18.99 for two-digit minor-unit currencies. No floating-point amount or authoritative client total is introduced. Clients using IEEE-754 numbers must handle integers beyond their exact range appropriately.

Paginated responses use native Laravel `data`, `links.first/last/prev/next`, and `meta.current_page/from/last_page/links/path/per_page/to/total`. URLs are generated by the paginator; metadata `links` contains Laravel's page navigation entries. Empty first pages use `total=0`, `last_page=1`, and `from=to=null`. The general envelope example above is an abbreviated metadata example.

Invalid queries return 422 `VALIDATION_FAILED`, e.g. reversed ranges:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","details":{"fields":{"max_price":["The maximum price must be greater than or equal to the minimum price."]}},"request_id":"<generated-uuid>"}}
```

404 uses `RESOURCE_NOT_FOUND` and `The requested resource was not found.` All responses retain Milestone 1 request IDs, no-store headers, and safe JSON errors.

Scope discrepancy: original FR-P03 includes SKU/description search; the explicit Milestone 2 request implements name search only and preserves that broader requirement for later review. The approved `price_minor` schema is retained instead of adding a literal `price` database column.

### Cart (implemented in Milestone 3)

| Method/path | Body | Success |
|---|---|---|
| `GET /api/cart` | none | `200` cart estimate |
| `POST /api/cart/items` | `product_id`, `quantity` | `201` cart |
| `PATCH /api/cart/items/{id}` | `quantity` | `200` cart |
| `DELETE /api/cart/items/{id}` | none | `204` |
| `POST /api/cart/promotion` | `code` | `200` updated cart estimate (Milestone 4) |
| `DELETE /api/cart/promotion` | none | `204` empty body, idempotent (Milestone 4) |

Cart line `{id}` is resolved inside the authenticated customer's cart scope, never by product ID. All six endpoints require bearer authentication. Promotion POST supersedes the earlier PUT proposal.

POST requires `product_id` and `quantity`; PATCH requires `quantity`. Both use actual positive JSON integer values up to `9223372036854775807` (`integer:strict`): strings, floats, booleans, arrays, missing/null input, zero, negatives, and overflow return 422 `VALIDATION_FAILED` with field errors. A valid numeric ID for a missing product returns 404. Extra request keys are ignored, including `user_id`, `cart_id`, client prices/totals, and PATCH product reassignment; identity always comes from the authenticated customer.

POST adds the requested amount to the existing quantity, returning 201 for both new and merged lines. PATCH replaces it, returning 200. Quantity zero cannot remove a line. DELETE returns 204 with no body; deleting a missing/already-deleted/non-owned line returns 404. Missing, malformed, overflowing, and inaccessible line IDs all use the existing `RESOURCE_NOT_FOUND` envelope. Removing the final line retains the persisted cart ID.

Empty response for a customer without a persisted cart (GET performs no writes):

```json
{"data":{"id":null,"items":[],"subtotal":{"amount_minor":0,"currency":"USD"},"promotion":null,"promotion_eligibility":null,"estimated_discount":{"amount_minor":0,"currency":"USD"},"estimated_total":{"amount_minor":0,"currency":"USD"}}}
```

GET, POST, and PATCH use the same cart resource. Example with current price 1899 and quantity 2:

```json
{
  "data": {
    "id": 1,
    "items": [{
      "id": 7,
      "product": {
        "id": 1,
        "name": "Insulated Travel Mug",
        "sku": "DEMO-MUG",
        "description": "Stainless steel mug for daily travel.",
        "price": {"amount_minor":1899,"currency":"USD"},
        "stock_quantity": 5,
        "status": "active",
        "created_at": "2026-10-06T12:00:00.000000Z",
        "updated_at": "2026-10-06T12:00:00.000000Z"
      },
      "quantity": 2,
      "unit_price": {"amount_minor":1899,"currency":"USD"},
      "line_subtotal": {"amount_minor":3798,"currency":"USD"},
      "availability": {"is_available":true,"reason":null,"available_quantity":5}
    }],
    "subtotal": {"amount_minor":3798,"currency":"USD"},
    "promotion": null,
    "promotion_eligibility": null,
    "estimated_discount": {"amount_minor":0,"currency":"USD"},
    "estimated_total": {"amount_minor":3798,"currency":"USD"}
  }
}
```

The top-level allow-list is `id`, `items`, `subtotal`, `promotion`, `promotion_eligibility`, `estimated_discount`, `estimated_total`; lines expose exactly `id`, `product`, `quantity`, `unit_price`, `line_subtotal`, `availability`. Products use the catalogue's public fields. No owner/cart foreign keys, credentials, token records, internal database fields, usage records/counters, or price snapshots are exposed. Lines are ordered by ascending cart item ID. Money uses `config('catalogue.currency')`; updated product prices immediately affect estimates.

Unavailable items remain present, with their requested quantities and current-price subtotals included. Availability reason precedence is `inactive`, then `out_of_stock` (zero), then `insufficient_stock` (positive stock below quantity), else null with `is_available=true`. `available_quantity` reports stock even for inactive products. Owned cart product details intentionally include inactive products already present; public catalogue visibility is unchanged. Nothing is silently removed.

Mutations revalidate the affected product under lock: inactive returns 409 `PRODUCT_INACTIVE`; insufficient stock returns 409 `INSUFFICIENT_STOCK`, e.g.:

```json
{"error":{"code":"INSUFFICIENT_STOCK","message":"The requested quantity is no longer available.","details":{"product_id":1,"available":2},"request_id":"<generated-uuid>"}}
```

Other cart 409 codes are `CART_CONFLICT` for integrity/remaining lock conflicts and `CART_TOTAL_TOO_LARGE` if a line or cart amount exceeds signed bigint. Overflow returns no rounded total; failed POST/PATCH operations roll back. Current-price overflow can also make GET return 409; owned DELETE remains available to recover. SQL/stack traces remain private even in debug mode. Missing/invalid tokens return 401. All responses retain existing request IDs and no-store headers.

Adding/removing does not change inventory or reserve stock. Quantity checks concern only this customer's line; checkout must independently revalidate and allocate inventory. Cart GET is an unlocked estimate, not a guarantee or historical snapshot.

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
| `404` | `RESOURCE_NOT_FOUND` | Missing/non-owned resource; inactive catalogue detail |
| `409` | `INSUFFICIENT_STOCK` | Quantity cannot be fulfilled (cart implemented) |
| `409` | `PRODUCT_INACTIVE` | Cart product is no longer active |
| `409` | `CART_CONFLICT` | Cart integrity/remaining lock conflict |
| `409` | `CART_TOTAL_TOO_LARGE` | Current line/cart amount exceeds signed bigint |
| `409` | `EMPTY_CART` | Promotion application has no cart/items; checkout remains planned |
| `409` | `INVALID_ORDER_STATUS` | Status transition/cancellation is not allowed |
| `422` | `VALIDATION_FAILED` | Request field validation |
| `404` | `PROMOTION_NOT_FOUND` | Unknown normalized code |
| `422` | `PROMOTION_INACTIVE` | Code is inactive |
| `422` | `PROMOTION_NOT_STARTED` | Before inclusive start |
| `422` | `PROMOTION_EXPIRED` | At/after exclusive expiry |
| `409` | `PROMOTION_GLOBAL_USAGE_LIMIT_REACHED` | Global ledger count exhausted |
| `409` | `PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED` | Customer ledger count exhausted |
| `409` | `INVALID_CART_STATE` | At least one line is not purchasable |
| `422` | `PROMOTION_MINIMUM_NOT_MET` | Subtotal below threshold |
| `429` | `TOO_MANY_REQUESTS` | Rate limit exceeded |
| `500` | `INTERNAL_ERROR` | Unexpected failure; details not exposed |
| `503` | `SERVICE_UNAVAILABLE` | Optional readiness/dependency failure |

Authentication, catalogue, cart, and promotion mappings above are implemented. Checkout/order-specific mappings and endpoints remain planned. Product endpoints use the existing validation and not-found mappings.

## Resource outline

A product exposes `id`, `name`, `sku`, `description`, money-valued `price`, `stock_quantity` (or a future availability abstraction), `status`, and timestamps. An order exposes status, currency, subtotal, discount, total, optional promotion-code snapshot, cancellation timestamp, timestamps, and item snapshots. Passwords, password hashes, internal lock/counter fields, and unrelated foreign keys are never exposed. A new token is exposed only in its registration/login issuance response.

## Promotion API details (Milestone 4)

POST body: `{"code":"SUMMER20"}`. Normalize trim/uppercase, then require a string of 1..64 ASCII letters/digits/underscore/hyphen beginning with letter/digit. Extra fields are ignored, including owner/cart IDs and client money. Resolve identity only from the token. Apply replaces one selected code and returns 200; repeated application neither consumes nor reserves usage. Unknown codes return 404; business failures use the table above and preserve any existing selection.

DELETE returns an empty 204 for selected, unselected, or absent carts. It removes no items and changes no stock/ledger records.

Example: current-price subtotal 15000, SUMMER20 value 2000 basis points, minimum 10000, cap 2500. The usual `id`/`items` fields remain present; these are the estimate fields:

```json
{
  "subtotal": {"amount_minor":15000,"currency":"USD"},
  "promotion": {
    "id": 1,
    "code": "SUMMER20",
    "type": "percentage",
    "percentage_basis_points": 2000,
    "fixed_amount": null,
    "minimum_cart_amount": {"amount_minor":10000,"currency":"USD"},
    "maximum_discount": {"amount_minor":2500,"currency":"USD"},
    "starts_at": null,
    "expires_at": null
  },
  "promotion_eligibility": {"is_eligible":true,"reason":null,"message":null},
  "estimated_discount": {"amount_minor":2500,"currency":"USD"},
  "estimated_total": {"amount_minor":12500,"currency":"USD"}
}
```

Fixed promotions use `type="fixed"`, `percentage_basis_points=null`, and money-valued `fixed_amount`. No ambiguous raw `value`, usage limit/count, customer FK, or ledger record is public. Currency labels use catalogue configuration.

Without a selection, promotion/eligibility are null. With an invalid selection, GET retains its information, returns a reason/message, zero discount, and total equal to subtotal. For example:

```json
{
  "promotion_eligibility": {
    "is_eligible": false,
    "reason": "PROMOTION_EXPIRED",
    "message": "The promotion has expired."
  },
  "estimated_discount": {"amount_minor":0,"currency":"USD"},
  "estimated_total": {"amount_minor":15000,"currency":"USD"}
}
```

GET, item POST/PATCH, and promotion POST share this contract. Prices, quantities, availability, active status, validity windows, and ledger counts are evaluated afresh. Failure precedence: empty cart → invalid items → inactive → not started → expired → minimum → global usage → customer usage. An inactive/expired code therefore reports inactive. Application failure uses the same reason/message in the normal error envelope:

```json
{"error":{"code":"PROMOTION_EXPIRED","message":"The promotion has expired.","request_id":"<generated-uuid>"}}
```

All totals are estimates; future checkout must revalidate under locks and record successful redemption atomically. No checkout/order/redemption endpoint is registered.
