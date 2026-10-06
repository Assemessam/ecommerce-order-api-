# 06 — Database Design

This is the target design for later milestones. Milestone 1 still contains only the existing Laravel/Sanctum framework tables; authentication requires no additional migration.

## Conventions

- PostgreSQL `bigint` identity primary keys and `timestamptz`-equivalent Laravel timestamps.
- Monetary amounts use signed `bigint` integer minor units (for example, cents) and API resources format them deliberately. No `float`/`double`.
- Promotion percentage uses integer basis points (`10000 = 100%`) to avoid floating-point arithmetic.
- Foreign keys are indexed; deletion behavior is explicit.
- Closed business values use PHP backed enums plus database check constraints.

## Proposed tables

### `users`

The existing Laravel user table has unique email and timestamps. Auth requests and the User email mutator trim/lowercase email before Eloquent writes; the PostgreSQL unique constraint enforces canonical email uniqueness. Direct SQL imports must normalize email themselves. Sanctum uses the existing `personal_access_tokens` migration and hashes stored token values.

### `products`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `name` | Required string |
| `sku` | Required unique identifier; proposed case-insensitive uniqueness |
| `description` | Nullable text |
| `price_minor` | `bigint`, check `>= 0` |
| `stock_quantity` | `bigint`, check `>= 0` |
| `status` | `active` or `inactive` |
| timestamps | Audit fields |

Indexes: unique normalized SKU; `(status, id)`; `(status, price_minor)`; optional PostgreSQL full-text/trigram index only after measured need.

### `carts`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `user_id` | Unique FK to users; one cart/customer |
| `promotion_id` | Nullable FK; selected promotion is only provisional |
| timestamps | Audit fields |

Deleting a user may cascade to their active cart subject to future retention policy. A promotion referenced by a cart should use `nullOnDelete`.

### `cart_items`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `cart_id` | FK, cascade on cart deletion |
| `product_id` | FK, restrict deletion while referenced |
| `quantity` | Positive integer |
| timestamps | Audit fields |

Unique `(cart_id, product_id)` prevents duplicate lines. Cart items do not persist an authoritative price.

### `promotions`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `code` | Required, normalized, case-insensitive unique |
| `type` | `percentage` or `fixed` |
| `value` | Basis points for percentage; minor units for fixed |
| `minimum_cart_amount_minor` | Nullable/non-negative |
| `maximum_discount_minor` | Nullable/non-negative; meaningful primarily for percentage |
| `starts_at`, `ends_at` | Nullable inclusive validity bounds; start <= end |
| `global_usage_limit` | Nullable positive integer |
| `per_customer_usage_limit` | Nullable positive integer |
| `is_active` | Boolean |
| timestamps | Audit fields |

The type determines the `value` constraint: percentage `1..10000`; fixed `> 0`.

### `orders`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `user_id` | FK, restrict deletion for audit history |
| `status` | Proposed order-status enum |
| `currency` | ISO-style 3-character code, proposed single configured value |
| `subtotal_minor` | Non-negative |
| `discount_minor` | Non-negative and `<= subtotal_minor` |
| `total_minor` | Non-negative; check `subtotal - discount = total` |
| `promotion_id` | Nullable FK, restrict or retain via snapshot policy |
| `promotion_code` | Nullable historical code snapshot |
| `cancelled_at` | Nullable timestamp |
| `inventory_restored_at` | Nullable timestamp; exactly-once restoration marker |
| timestamps | Audit fields |

Indexes: `(user_id, created_at desc)` and `(status, created_at)`.

### `order_items`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `order_id` | FK, cascade only if order deletion is ever allowed |
| `product_id` | Nullable/restricted FK according to retention decision |
| `product_name` | Snapshot |
| `product_sku` | Snapshot |
| `unit_price_minor` | Non-negative snapshot |
| `quantity` | Positive integer |
| `line_total_minor` | Check `unit_price_minor * quantity = line_total_minor` where practical |
| timestamps | Audit fields |

Historical snapshot fields are authoritative for displaying an old order.

### `promotion_usages`

| Column | Notes |
|---|---|
| `id` | Primary key |
| `promotion_id` | FK, restrict deletion |
| `user_id` | FK, restrict deletion pending retention policy |
| `order_id` | Unique FK; one usage per order |
| `discount_minor` | Non-negative applied discount snapshot |
| `used_at` | Timestamp |

Indexes: `(promotion_id, user_id)` for per-customer counts and `(promotion_id, used_at)` for global counts. A denormalized `used_count` on promotions is optional; if added, it is authoritative only when updated under the promotion lock in the same transaction.

## Relationships

- User `1—1` Cart; Cart `1—many` CartItem; Product `1—many` CartItem.
- User `1—many` Order; Order `1—many` OrderItem; Product `1—many` OrderItem references plus snapshots.
- Promotion `1—many` Carts, Orders, and PromotionUsages.
- User `1—many` PromotionUsages; Order `1—0..1` PromotionUsage.

## Integrity and deletion policy

Catalogue and promotion records should normally be deactivated, not deleted. Orders and usage records are audit data and should not be cascade-deleted as routine account cleanup. Exact privacy/retention handling remains an open business requirement.
