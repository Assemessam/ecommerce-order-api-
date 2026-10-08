# 07 — API Contracts

## General conventions

- Base path: `/api` as required (no version prefix in the assessment contract).
- Media type: `application/json`; clients send `Accept: application/json`.
- Timestamps: ISO 8601 UTC strings.
- Money: product prices and cart/order totals use objects with integer `amount_minor` and 3-letter `currency`. Order-item snapshots and administrative inputs expose the documented integer `_minor` fields; promotion `value` uses minor units for fixed discounts and basis points for percentages. Checkout never accepts client-supplied authoritative totals.
- Product, order, and administrative collection page sizes default to 15 and are capped at 100.
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

Registration permits 5 attempts/hour/IP. Login permits 5 attempts/minute/normalized-email-and-IP and 30 attempts/minute/IP, plus 20 attempts/minute/normalized account across IPs. Successes and failures count, and throttled responses include `Retry-After`. Duplicate emails return 422 `VALIDATION_FAILED` with `details.fields.email`, including races detected by the database constraint. Invalid credentials return 401 `UNAUTHENTICATED` with `The provided credentials are incorrect.` Missing/invalid/revoked tokens return 401 with `Unauthenticated.`

Authentication uses bearer tokens only, without web session fallback. Issued API tokens retain native Sanctum no-expiration defaults and `*` abilities; customers have no internal role. Logout deletes only the authenticating token; other tokens remain valid. Unverified accounts may authenticate; verification and password recovery are outside Milestone 1. POST logout supersedes the foundation's DELETE proposal per the approved milestone request.

### Products (implemented in Milestone 2; public, no token required)

| Method/path | Query | Success |
|---|---|---|
| `GET /api/products` | `search`, `min_price`, `max_price`, `available`, `sort`, `direction`, `page`, `per_page` | `200` paginated products |
| `GET /api/products/{id}` | none | `200` product |

| Parameter | Validation and behavior |
|---|---|
| `search` | Optional nullable string, trimmed, maximum 100 characters; empty/whitespace means no search; embedded null bytes rejected. Literal case-insensitive substring of **name, SKU, or description**; the OR predicates are grouped with visibility and other filters. `%`, `_`, and backslash are escaped, values bound via `whereLike`/PostgreSQL `ILIKE`. |
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

Milestone 7 closes the recorded FR-P03 search gap: name, SKU, and description are searchable. The approved `price_minor` schema is retained instead of adding a literal `price` database column.

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

### Checkout (implemented in Milestone 5)

`POST /api/checkout` requires a Sanctum bearer token. The persisted owner cart and selected promotion are authoritative. No body/query parameters are supported; extra fields (including forged identity, prices, names, quantities, coupons, and totals) are ignored. Optional `Idempotency-Key` is the only accepted input: 1..128 case-sensitive ASCII characters, beginning with a letter/digit and continuing with letters/digits/period/underscore/colon/hyphen. Empty, whitespace, malformed, or oversized keys return 422 with `error.details.fields.idempotency_key`.

- First successful checkout returns 201 with OrderResource, snapshots, totals, and promotion inputs.
- Reusing a successful key for the same customer returns 200 with the original persisted order, before any empty-cart/eligibility rejection. It performs no second deduction/redemption and leaves a newly filled cart untouched. A fresh purchase requires a new key.
- Keys are scoped to customers and retained for the life of the order, without expiry. There is no payload fingerprint because the API accepts no purchase parameters; ignored fields do not alter replay semantics.
- Different/no keys after a successful checkout return 409 CART_EMPTY until the cart is filled again. Failed attempts persist no key association and can be retried.

Example request:

```http
POST /api/checkout
Accept: application/json
Authorization: Bearer <token>
Idempotency-Key: purchase-2026-10-06-001
```

Example 201 response (200 replay returns the same resource):

```json
{
  "data": {
    "id": 1,
    "user_id": 7,
    "status": "placed",
    "currency": "USD",
    "items": [{"id":1,"product_id":42,"product_name":"Travel Mug","product_sku":"MUG","quantity":2,"unit_price_minor":1005,"line_subtotal_minor":2010}],
    "subtotal": {"amount_minor":2010,"currency":"USD"},
    "discount": {"amount_minor":201,"currency":"USD"},
    "total": {"amount_minor":1809,"currency":"USD"},
    "promotion": {"id":3,"code":"SAVE10","type":"percentage","value":1000,"maximum_discount":null},
    "cancelled_at": null,
    "inventory_restored_at": null,
    "placed_at": "2026-10-06T12:00:00.000000Z",
    "created_at": "2026-10-06T12:00:00.000000Z",
    "updated_at": "2026-10-06T12:00:00.000000Z"
  }
}
```

Without a selected promotion, promotion is null and discount is zero. Item amounts inherit the order's currency. Idempotency keys and live catalogue/promotion relations are not exposed. The first response reloads the persisted order so timestamp precision matches later replays.

Errors preserve the existing request ID/no-store JSON envelope: 401 UNAUTHENTICATED; 409 CART_EMPTY, PRODUCT_INACTIVE, INSUFFICIENT_STOCK, CART_TOTAL_TOO_LARGE, CHECKOUT_CONFLICT, or exhausted usage codes; 422 inactive/future/expired/minimum coupon codes; 404 missing product/unknown promotion; safe 500 INTERNAL_ERROR for unexpected failures. Any failed transaction preserves items, quantities, selected promotion, and stock and creates no partial order or consumed usage. See `11-checkout.md` for transaction/locking details.

### Orders (implemented in Milestone 6)

| Method/path | Query/body | Success |
|---|---|---|
| `GET /api/orders` | `page`, `per_page` | `200` paginated own orders |
| `GET /api/orders/{id}` | none | `200` own order with items |
| `POST /api/orders/{id}/cancel` | none | `200` cancelled order |

All three endpoints require Sanctum. History accepts only query `page` (1..2147483647) and `per_page` (1..100), default 1/15. Sort is fixed to `created_at DESC, id DESC`. Arbitrary user IDs/sort fields and GET-body pagination are ignored; only validated query pagination appears in links. Lists omit `items`, with the same stored totals/currency/status/audit fields as details. No live products/promotions or customer profile are loaded. Details eagerly load stored items.

Example requests:

```http
GET /api/orders?page=1&per_page=15
Accept: application/json
Authorization: Bearer <token>

GET /api/orders/1
Accept: application/json
Authorization: Bearer <token>

POST /api/orders/1/cancel
Accept: application/json
Authorization: Bearer <token>
```

List response is `data: [OrderResource summaries]`, native Laravel `links`, and pagination `meta` as described above. GET detail has the full checkout response shape above, with nullable `cancelled_at` and `inventory_restored_at`. First/repeated cancellation has the same full shape with `status: cancelled` and equal, non-null audit timestamps; `updated_at` reflects the first cancellation and never changes on retry. See the complete JSON examples in `12-order-management.md`.

POST accepts no cancellation parameters. Extra body/query values cannot change identity, quantities, snapshots, prices, status, timestamps, or restoration. Only placed orders can transition to cancelled. A repeat returns 200 without another inventory update; missing/non-owned/malformed/overflowing IDs return the same 404. Unauthenticated access returns 401, invalid list pagination 422, unsupported/inconsistent state 409 INVALID_ORDER_STATUS or ORDER_CANCELLATION_CONFLICT, restoration overflow 409 INVENTORY_RESTORATION_OVERFLOW, integrity/exhausted concurrency conflicts 409 ORDER_CANCELLATION_CONFLICT, and unexpected failures generic 500 INTERNAL_ERROR. Responses preserve error.request_id / X-Request-ID and no-store/private headers.

All stock updates, status, and markers share one transaction. A failure preserves placed state and original stock; no partial restores survive. Product/item/promotional snapshots and original promotion usage are preserved. Replaying the original checkout key after cancellation returns 200 with the existing cancelled order and does not create a new purchase or consume stock/usage.


## Status and error mapping

| HTTP | Stable code | Use |
|---|---|---|
| `400` | `MALFORMED_REQUEST` | Invalid JSON or structurally unreadable request |
| `401` | `UNAUTHENTICATED` | Missing/invalid token or bad credentials |
| `403` | `FORBIDDEN` | Action is forbidden |
| `405` | `METHOD_NOT_ALLOWED` | Method is unsupported |
| `409` | `CONFLICT` | Generic HTTP conflict |
| `404` | `RESOURCE_NOT_FOUND` | Missing/non-owned resource; inactive catalogue detail |
| `409` | `INSUFFICIENT_STOCK` | Quantity cannot be fulfilled (cart and checkout implemented) |
| `409` | `PRODUCT_INACTIVE` | Cart/checkout product is no longer active |
| `409` | `CART_CONFLICT` | Cart integrity/remaining lock conflict |
| `409` | `CART_TOTAL_TOO_LARGE` | Current line/cart amount exceeds signed bigint |
| `409` | `EMPTY_CART` | Promotion application has no cart/items |
| `409` | `CART_EMPTY` | Checkout has no cart/items |
| `409` | `CHECKOUT_CONFLICT` | Checkout integrity/exhausted concurrency conflict |
| `409` | `INVALID_ORDER_STATUS` | Order is not eligible for cancellation |
| `409` | `INVENTORY_RESTORATION_OVERFLOW` | Restoring inventory exceeds signed bigint |
| `409` | `ORDER_CANCELLATION_CONFLICT` | Inconsistent/incomplete order, integrity or remaining concurrency conflict |
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

Authentication, catalogue, cart, promotion, and checkout mappings/endpoints above are implemented. Order browsing/cancellation are implemented in Milestone 6. Product endpoints use the existing validation and not-found mappings.

## Resource outline

A product exposes `id`, `name`, `sku`, `description`, money-valued `price`, `stock_quantity` (or a future availability abstraction), `status`, and timestamps. An order exposes ID/customer, placed/cancelled status, purchase currency, subtotal, discount, total, optional historical promotion calculation inputs, purchase/audit timestamps, and item snapshots. Orders expose nullable cancelled_at and inventory_restored_at; summaries omit items. Passwords, password hashes, internal lock/counter fields, and unrelated foreign keys are never exposed. A new token is exposed only in its registration/login issuance response.

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

This was the Milestone 4 delivery state: totals were estimates and checkout was deferred. The completed checkout/order contracts above supersede that state; checkout now revalidates under locks and records redemption atomically.

## Bonus 7A — Administrator product and promotion management

All routes below require a Sanctum bearer token and the current Spatie permission for that action. Missing/invalid/revoked tokens receive 401; an authenticated user lacking permission receives 403 before validation or resource lookup, unless repeated attempts have exhausted the user's category quota (429). Customers have no internal role. Product Manager receives the four product/inventory permissions; Promotion Manager receives the three promotion permissions; Administrator receives all seven; both manager roles provide their union. Existing tokens reflect role changes. The retained `is_admin` flag grants no access. There are no public role-management, admin-registration, or DELETE endpoints. Route names remain `admin.products.{index,show,store,update}` and `admin.promotions.{index,show,store,update}`.

| Endpoint | Required permission |
|---|---|
| GET `/api/admin/products` | `products.view-admin` |
| GET `/api/admin/products/{id}` | `products.view-admin` |
| POST `/api/admin/products` | `products.create` |
| PATCH `/api/admin/products/{id}` | `products.update`; additionally `inventory.adjust` whenever `stock_adjustment` is present |
| GET `/api/admin/promotions` | `promotions.view-admin` |
| GET `/api/admin/promotions/{id}` | `promotions.view-admin` |
| POST `/api/admin/promotions` | `promotions.create` |
| PATCH `/api/admin/promotions/{id}` | `promotions.update` |

Inventory authorization runs before validation and again in ProductService, so a denied stock adjustment cannot partially edit metadata. All staff remain subject to customer ownership policies. No request/resource shape or endpoint was added by the refactor.

| Method | Path | Success |
|---|---|---|
| GET | `/api/admin/products` | 200 paginated ProductResource collection, including inactive |
| GET | `/api/admin/products/{id}` | 200 ProductResource, including inactive |
| POST | `/api/admin/products` | 201 ProductResource |
| PATCH | `/api/admin/products/{id}` | 200 ProductResource |
| GET | `/api/admin/promotions` | 200 paginated AdminPromotionResource collection |
| GET | `/api/admin/promotions/{id}` | 200 AdminPromotionResource |
| POST | `/api/admin/promotions` | 201 AdminPromotionResource |
| PATCH | `/api/admin/promotions/{id}` | 200 AdminPromotionResource |

Product lists reuse public search/price/availability/sort/pagination semantics and add optional `status=active|inactive`; absent status includes both. Promotion lists use newest-created/ID order, optional `is_active=true|false`, default page size 15 and maximum 100. Filters are query-only, allow-listed and carried into links. Admin-only aggregate `redemptions_count` reveals no customer identities. Missing/zero/out-of-range identifiers return 404; empty PATCH is a permitted no-op.

Product POST requires `name`, `sku`, `price_minor`; optional description, stock_quantity and status default to null, 0 and active. Names and SKUs are trimmed, max 255; description is nullable and bounded to 10,000 characters. SKU normalizes to uppercase; the existing normalized unique index is authoritative, including legacy lowercase imports. Price/stock are actual JSON integers in 0..PHP_INT_MAX. PATCH accepts the same properties except stock_quantity, plus a nonzero signed `stock_adjustment` in −PHP_INT_MAX..PHP_INT_MAX. Supplying stock_quantity on PATCH or stock_adjustment on POST returns 422, even if null.

```json
{"name":"Desk Lamp","sku":"lamp-01","price_minor":1899,"stock_quantity":10,"status":"active"}
```

```json
{"name":"Updated Lamp","price_minor":2099,"description":null,"stock_adjustment":-2,"status":"inactive"}
```

Promotion POST requires `code`, `type`, `value`; PATCH makes these optional. Code is canonical ASCII `[A-Z0-9][A-Z0-9_-]{0,63}` after trimming/uppercasing. Types are `percentage` (value 1..10000 basis points) and `fixed` (value 1..PHP_INT_MAX minor units). Optional minimum_cart_amount_minor defaults to 0 and must be a nonnegative integer; maximum_discount_minor is null or a positive integer. Validity fields are null or explicit-offset ISO timestamps, using whole seconds or exactly six fractional digits; `Z` is accepted and responses are UTC. If both boundaries exist, starts_at must precede expires_at. Usage limits are null or positive integers; is_active is an actual JSON boolean, default true. Explicit null clears caps/dates/limits. Omitted PATCH fields retain existing values; combined type/value and date rules are checked against the locked row.

```json
{"code":"save20","type":"percentage","value":2000,"minimum_cart_amount_minor":10000,"maximum_discount_minor":2500,"global_usage_limit":100,"per_customer_usage_limit":2,"is_active":true}
```

```json
{"type":"fixed","value":500,"maximum_discount_minor":null,"expires_at":"2026-12-31T23:59:59Z","is_active":false}
```

The admin promotion resource uses explicit editable field names (including raw value/minor-unit inputs), UTC dates, redemptions_count, and created_at/updated_at. Customer cart promotion resources retain their existing money/basis-point schema. Updates affect future eligibility and pricing; existing orders and ledger records remain unchanged. Codes already selected in carts continue to reference the same promotion ID. Cancellation keeps usage consumed.

| Condition | Status / code |
|---|---|
| Invalid fields, duplicate normalized SKU/code, invalid combined promotion state | 422 / VALIDATION_FAILED, `details.fields` |
| Stock delta would produce negative stock or bigint overflow | 409 / INVENTORY_ADJUSTMENT_CONFLICT |
| Supplied limit below total or largest individual consumed usage | 409 / PROMOTION_USAGE_LIMIT_CONFLICT |
| Exhausted recognized database contention/integrity conflict | 409 / ADMINISTRATION_CONFLICT |
| Unexpected failure | 500 / INTERNAL_ERROR; no SQL/details exposed |

A limit equal to usage is permitted and blocks further redemption; reducing below usage is rejected atomically, including any other fields in that PATCH. Stock adjustments are additive and **not idempotent across separate requests**; don't blindly retry after an uncertain response. Transaction retries roll back earlier attempts before reapplying a delta. Current local grant/revoke commands and permission behavior are in [19-product-service-rbac-refactor.md](19-product-service-rbac-refactor.md); [14-admin-management.md](14-admin-management.md) retains historical curl examples. The separate Admin Postman folder uses its own token variables.

## Bonus 7B — Public listing freshness

`GET /api/products` now uses a server-side Redis read-through cache. Its filters, validation, active visibility, ordering, JSON fields, pagination metadata/links, request-ID headers, and `Cache-Control: no-store, private` transport policy are unchanged. Normalized equivalent queries can reuse a page while each request retains its own validated pagination parameters and host/path. Invalid parameters are rejected before service/cache access. Detail and administrator reads remain uncached.

Listings are short-lived estimates with a configurable 45-second default cache budget. Successful catalogue/stock mutations rotate the listing generation after database commit; rollback retains the committed cache. A request already reading before a mutation may return its earlier result. Failed invalidation/process failure can leave old entries reachable until expiry. Redis outages use PostgreSQL fallback; no cache connection details reach clients. Checkout always revalidates PostgreSQL status, stock and current prices under its original row locks, irrespective of listing data. See [15-redis-caching.md](15-redis-caching.md).


## Bonus 7D rate-limit response contract

Every defined `/api` route has its category's named limiter. Defaults: public catalogue/health 120/minute/IP in separate groups; cart/history/profile reads 120/minute/user; cart writes 60, promotion changes 30, checkout/cancellation 20; admin reads 120 and writes 30; logout 30. Categories are separate, while related routes within one category share a budget. Every authenticated identity is stable `user:<id>` across tokens and role changes; roles/permissions are excluded from its key. The complete path/policy/env matrix is in [17-api-rate-limiting.md](17-api-rate-limiting.md).

```json
{"error":{"code":"TOO_MANY_REQUESTS","message":"Too many requests. Please try again later.","request_id":"generated-uuid"}}
```

The existing uppercase code is preserved. A denied request returns HTTP 429, generated `X-Request-ID`, `Cache-Control: no-store, private`, native `Retry-After` seconds, `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0` and `X-RateLimit-Reset` epoch seconds. Normal responses that pass the limiter receive its limit and remaining headers; an early 401 does not. HEAD responses have the same status/headers with no body. For login, headers represent the native selected constraint, not the sum of the three budgets. Retry-After refers to the constraint that rejected this request; another constraint can still reject a subsequent retry.

Windows begin on first admission and do not slide with rejected requests. Counted validation/business/authorization failures and idempotency replays use allowance. A throttle prevents checkout changes, including outbox insertion; retrying after the window preserves customer-scoped purchase idempotency. HTTP 503 `SERVICE_UNAVAILABLE` indicates limiter connection failure; no fabricated rate-limit/retry headers are attached. This availability choice fails closed before services run. Guests receive 401 first; authenticated requests consume quota before route authorization, so repeated forbidden requests can receive 429. `/up` remains an internal liveness endpoint outside the public API policies.
