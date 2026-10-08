# Local demonstration data

`DemoDatabaseSeeder` creates an interconnected catalogue, staff accounts, customers, carts, and legitimate purchases for local API demonstrations. It is explicitly opt-in. The default `DatabaseSeeder` runs only `AuthorizationSeeder`, which restores the canonical role and permission definitions without creating users or granting user roles.

## Seed an existing local installation

Follow the main [installation guide](../../README.md#installation-and-database-setup) first if dependencies or the application key are missing. Preserve any existing `.env`, application key, database, and Docker volumes.

Set `APP_ENV=local` and a private `DEMO_SEED_PASSWORD` in your uncommitted `.env`. There is no default demonstration password. Use 12–72 bytes without a NUL byte; choose a strong password suitable for your local setup. All eight newly created accounts receive a Laravel hash of this configured password. Existing passwords are never reset by a rerun.

```bash
docker compose up -d api
docker compose exec -T api php artisan config:clear --no-interaction
docker compose exec -T api php artisan migrate --no-interaction
# Optional explicit restoration of essential authorization definitions:
docker compose exec -T api php artisan db:seed --no-interaction
# Opt in to the demonstration dataset:
docker compose exec -T api php artisan db:seed --class=DemoDatabaseSeeder --no-interaction
```

The coordinator and every demo child reject environments other than `local` or `testing`, non-PostgreSQL connections, and an absent or invalid password before writing. Do not invoke demo seeders during production deployment. No seeder performs migrations, truncation, foreign-key disabling, or wholesale deletion. The original `ProductSeeder` and `PromotionSeeder` remain independent additive sample seeders; their fixtures have different identifiers and are not part of the counts below.

## Architecture and dependency order

The coordinator executes:

1. `AuthorizationSeeder`: three roles, seven permissions, and their canonical mappings.
2. `DemoUserSeeder`: three staff identities and five role-less customers.
3. `DemoProductSeeder`: sixteen products through `ProductService` and typed DTOs.
4. `DemoPromotionSeeder`: nine promotions through the administration service and typed DTOs.
5. `DemoOrderSeeder`: seven purchases through cart, promotion, and checkout services; one cancellation through `OrderService`.
6. `DemoCartSeeder`: the two remaining active customer carts through cart and promotion services.

Orders run before the final active carts because checkout empties purchased carts. Use the coordinator rather than individual child seeders: the children require already seeded users, authorization, products, and promotions. `DemoSeeder` centralizes the environment, PostgreSQL, and password guards without changing application services or repositories.

The ten application models each already have a corresponding factory: `User`, `Product`, `Promotion`, `Cart`, `CartItem`, `Order`, `OrderItem`, `PromotionRedemption`, `OrderOutboxEvent`, and `OrderNotification`. The demonstration transactions use the actual workflows rather than the order factory, so stock, price snapshots, usage records, and outbox events are produced together.

## Complete table inventory

The nineteen application migration files create twenty-three tables. Laravel creates the `migrations` ledger, giving twenty-four tables in total. Counts below describe a clean migrated database immediately after one complete demo run, before manual API requests or background processing. Existing application data increases total counts.

| Table | Model | Class / purpose | Relationships | Seeder / strategy | Expected rows |
|---|---|---|---|---|---:|
| `users` | `User` | Business: customers and staff | Own carts, orders, and redemptions; role mappings | `DemoUserSeeder`; stable reserved emails | 8 |
| `products` | `Product` | Business: catalogue and available inventory | Referenced by cart and historical order items | `DemoProductSeeder`; stable SKUs, service creation | 16 |
| `promotions` | `Promotion` | Business: discount eligibility | Referenced by carts, orders, and redemptions | `DemoPromotionSeeder`; stable codes, service creation | 9 |
| `carts` | `Cart` | Business: current purchase intent | One per customer; optional promotion; many items | Orders create three empty carts; `DemoCartSeeder` creates two active carts | 5 |
| `cart_items` | `CartItem` | Business: quantities to purchase | Cart and product; unique product per cart | `DemoCartSeeder` through `CartService` | 3 |
| `orders` | `Order` | Transactional: committed purchase history | Customer, optional promotion, items, optional redemption | `DemoOrderSeeder` through checkout and cancellation | 7 |
| `order_items` | `OrderItem` | Transactional: immutable purchase snapshots | Order and product | Created by checkout | 10 |
| `promotion_redemptions` | `PromotionRedemption` | Transactional: consumed usage | Promotion, customer, unique optional order | Created by discounted checkout; retained on cancellation | 5 |
| `order_outbox_events` | `OrderOutboxEvent` | Transactional: reliable order event envelopes | Order; unique order/event-type pair | Created by seven placements and one cancellation | 8 |
| `order_notifications` | `OrderNotification` | Transactional: actual internal processing effects | Order and unique outbox event | Intentionally empty; the real worker creates effects | 0 |
| `roles` | Spatie `Role` | Authorization: internal roles | User role mappings and permission mappings | `AuthorizationSeeder` | 3 |
| `permissions` | Spatie `Permission` | Authorization: approved capabilities | Role and optional direct-user mappings | `AuthorizationSeeder` | 7 |
| `role_has_permissions` | Pivot | Authorization: role capabilities | Role and permission | `AuthorizationSeeder`; exact canonical mappings | 14 |
| `model_has_roles` | Polymorphic pivot | Authorization: staff role assignments | User and role | `DemoUserSeeder` through Spatie; one role per new staff user | 3 |
| `model_has_permissions` | Polymorphic pivot | Authorization: optional direct permissions | User and permission | Intentionally empty; demo authority comes from roles | 0 |
| `personal_access_tokens` | Sanctum `PersonalAccessToken` | Infrastructure: actual authentication tokens | Polymorphic user owner | Intentionally empty; successful API login issues real tokens | 0 |
| `password_reset_tokens` | — | Infrastructure: actual password recovery | Email identity | Intentionally unseeded | 0 |
| `sessions` | — | Infrastructure: actual sessions | Optional user | Intentionally unseeded | 0 |
| `cache` | — | Infrastructure: database cache entries | Cache keys | Intentionally unseeded | 0 |
| `cache_locks` | — | Infrastructure: database cache ownership | Cache lock keys | Intentionally unseeded | 0 |
| `jobs` | — | Infrastructure: queued jobs | Queue and serialized payload | Intentionally unseeded; order events use the configured Redis queue | 0 |
| `job_batches` | — | Infrastructure: real batch bookkeeping | Batch identifier | Intentionally unseeded | 0 |
| `failed_jobs` | — | Infrastructure: actual execution failures | Connection, queue, and failed payload | Intentionally unseeded | 0 |
| `migrations` | — | Infrastructure: schema history | Migration name and batch | Managed by Laravel migrations, never fabricated by a seeder | 19 |

There are no category, payment, fulfillment, stock-ledger, or separate checkout-idempotency tables. Products store available stock; `orders.user_id` plus `orders.idempotency_key` identify a purchase replay. Outbox and notification relationships are enforced by foreign keys even though those two models do not declare Eloquent relationship methods.

## Accounts and authorization

All emails use the reserved `example.test` domain. Names carry the `Demo | ` prefix, and new users have verified email timestamps and an account history beginning ninety days before seeding.

| Email | Role | Demonstration |
|---|---|---|
| `demo-admin@example.test` | `administrator` | All seven internal permissions |
| `demo-products@example.test` | `product_manager` | Product read/create/update and inventory adjustment |
| `demo-promotions@example.test` | `promotion_manager` | Promotion read/create/update |
| `demo-alex@example.test` | None | Standard and percentage-discount purchase history |
| `demo-maya@example.test` | None | Cancelled and fixed-discount purchase history |
| `demo-omar@example.test` | None | Historical, limited-use, and capped-discount purchases |
| `demo-lina@example.test` | None | Active two-product cart |
| `demo-noah@example.test` | None | Low-stock item and an attached fixed promotion |

Spatie's existing `web` permission namespace remains authoritative. The legacy `is_admin` flag grants no access and is not used to authorize these identities. No direct permissions or privileged customer roles are added. Existing demo accounts retain their passwords, attributes, and role assignments on rerun.

## Catalogue

Prices and quantities are integers. The displayed quantities are the opening fixture inventory; successful sample orders reduce it, and the cancellation restores its purchased quantity exactly once. Active carts do not reserve inventory.

| SKU | Product | Price, minor units | Opening stock | After demo orders | Status / scenario |
|---|---|---:|---:|---:|---|
| `DEMO-V1-KEYBOARD` | Wireless Keyboard | 4999 | 50 | 49 | Active; order and cart |
| `DEMO-V1-MOUSE` | Ergonomic Wireless Mouse | 2499 | 80 | 78 | Active; standard purchase |
| `DEMO-V1-HEADPHONES` | Noise Cancelling Headphones | 12999 | 20 | 19 | Active; percentage purchase |
| `DEMO-V1-MONITOR` | 27-inch 4K Monitor | 34999 | 10 | 9 | Active; premium, capped-discount purchase |
| `DEMO-V1-MUG` | Insulated Travel Mug | 1899 | 40 | 40 | Active; cancelled purchase restores stock |
| `DEMO-V1-STAND` | Aluminium Laptop Stand | 2999 | 25 | 24 | Active; fixed-discount purchase |
| `DEMO-V1-CABLE` | USB-C Charging Cable | 999 | 100 | 98 | Active; affordable accessory |
| `DEMO-V1-BACKPACK` | Canvas Commuter Backpack | 6500 | 30 | 29 | Active; historical purchase |
| `DEMO-V1-NOTEBOOK` | Recycled Paper Notebook | 499 | 200 | 197 | Active; affordable, high stock, historical purchase |
| `DEMO-V1-ORGANIZER` | Bamboo Desk Organizer | 1799 | 35 | 33 | Active; limited-use discount purchase |
| `DEMO-V1-LAMP` | Adjustable LED Desk Lamp | 3499 | 0 | 0 | Active but out of stock |
| `DEMO-V1-LIMITED` | Compact Phone Tripod | 1299 | 2 | 2 | Active; low stock, Noah's cart |
| `DEMO-V1-SPEAKER` | Portable Bluetooth Speaker | 7999 | 18 | 18 | Active |
| `DEMO-V1-HUB` | USB-C Desktop Hub | 5999 | 25 | 25 | Active; Lina's cart |
| `DEMO-V1-CHARGER` | Retired Wireless Charger | 3999 | 7 | 7 | Inactive; administrator visibility only |
| `DEMO-V1-MAT` | Discontinued Desk Mat | 1999 | 0 | 0 | Inactive and out of stock |

Public catalogue reads expose active products, including the active zero-stock lamp; inactive products require an authorized administrative read. Adding to a cart and checkout still enforce availability. No category fields or unsupported statuses are introduced.

## Promotions

Promotion percentages use basis points: `1000` means 10%, `2000` means 20%, and `1500` means 15%. Fixed amounts, minimums, and caps use integer minor units.

| Code | Type / value | Scenario |
|---|---|---|
| `DEMO-V1-WELCOME10` | Percentage / 1000 | Active; two consumed redemptions, including the cancelled order |
| `DEMO-V1-SAVE5` | Fixed / 500 | Active; fixed-discount purchase and Noah's attached cart promotion |
| `DEMO-V1-MINIMUM10` | Percentage / 1000 | Active; minimum subtotal 10000 |
| `DEMO-V1-CAPPED20` | Percentage / 2000 | Active; maximum discount 5000 |
| `DEMO-V1-EXPIRED10` | Percentage / 1000 | Expired thirty days before seeding; rejected for new purchases |
| `DEMO-V1-FUTURE10` | Percentage / 1000 | Starts thirty days after seeding and expires sixty days after |
| `DEMO-V1-INACTIVE10` | Percentage / 1000 | Disabled; rejected for new purchases |
| `DEMO-V1-LIMITED5` | Fixed / 500 | Global limit 2, per-customer limit 1; Omar has used it once |
| `DEMO-V1-HIGHMIN15` | Percentage / 1500 | Active; minimum subtotal 50000 for ineligible-cart checks |

Ordinary promotions start sixty days before the first seed and expire one hundred eighty days afterward. Dates use UTC and preserve the application's timestamp precision. Reruns preserve every existing promotion field, including dates and consumed usage; they do not extend an expired fixture. Usage is calculated from real redemption records, without fabricated counters. Cancelling an order preserves its redemption.

## Orders, carts, and outbox

All keys below are stable seeder references, scoped to the listed customer. They are not suggested keys for unrelated manual purchases. Amounts describe untouched fixtures on a clean database.

| Customer | Idempotency key | State | Subtotal | Discount | Total | Scenario |
|---|---|---|---:|---:|---:|---|
| Alex | `demo-v1:standard` | `placed` | 9997 | 0 | 9997 | Multiple products without promotion |
| Alex | `demo-v1:percentage` | `placed` | 12999 | 1300 | 11699 | 10% discount with exact integer rounding |
| Maya | `demo-v1:cancelled` | `cancelled` | 3798 | 380 | 3418 | Two mugs; stock restored once, usage retained |
| Maya | `demo-v1:fixed` | `placed` | 4997 | 500 | 4497 | Fixed discount on multiple products |
| Omar | `demo-v1:historical` | `placed` | 7997 | 0 | 7997 | Purchase timestamp forty-five days before seeding |
| Omar | `demo-v1:limited` | `placed` | 3598 | 500 | 3098 | Two organizers, consuming one limited-use redemption |
| Omar | `demo-v1:capped` | `placed` | 34999 | 5000 | 29999 | Monitor; discount limited by the cap |

Checkout creates ten historical item snapshots, deducts inventory, clears each purchased cart, records five redemptions, and creates seven placement events. Cancellation adds one cancellation event and fills both cancellation/restoration timestamps. The historical order is first constructed by the same real workflow; only its newly created order, item, and placement-event timestamps are then backdated together. Conventional order/item `created_at` and `updated_at` columns retain whole-second precision; `placed_at` and outbox timestamps retain microseconds. No totals, inventory, ownership, or event status are fabricated.

Alex, Maya, and Omar finish with empty carts. Lina has one keyboard and one hub. Noah has one low-stock tripod with `DEMO-V1-SAVE5` attached. This gives five carts, three active items, and two nonempty carts; attaching a promotion consumes no usage.

Immediately after seeding, eight outbox envelopes are `pending`, have zero processing/dispatch attempts, and have no ownership tokens or fabricated processing timestamps. The seeder dispatches no worker and creates no notification effects. A separately running scheduler/worker may process them normally, so stop this project's optional order services first if you need to inspect the initial pending state. Actual internal processing creates `order_notifications`; no external customer message is sent by seeding.

## Reruns and preservation

Run the same `DemoDatabaseSeeder` command again. Stable emails, normalized SKUs, promotion codes, and customer-scoped order keys prevent duplicates. Existing orders are skipped before any temporary purchase-cart setup, so reruns do not consume a customer's later manual cart. Existing active demo carts are preserved rather than repopulated after a manual checkout. Prices, stock, product status, promotion configuration, passwords, existing account roles, and historical transaction rows are not reset.

A reserved demo email with an unexpected name or a reserved product SKU with an unexpected name is rejected as a collision. Existing promotion codes are preserved. A missing demonstration purchase is rejected if its customer's cart already contains a promotion or items; the seeder will not check out or discard that customer's legitimate cart. Existing edits can make a newly missing fixture ineligible or unauthorized, causing a clear failure instead of overriding the edit. This is additive preservation, not a database reset or a guarantee to repair arbitrary deletions.

A coordinated run uses one PostgreSQL transaction and a transaction-scoped advisory lock to serialize fixture creation. Child seeders and application workflows participate in that transaction; an error rolls back the coordinated run rather than leaving a partially constructed demonstration. Direct child execution has the same guard and transactional protection for that child. No persistent lock records or external notification workflow are introduced. Correct the reported prerequisite and rerun: committed purchase keys and existing fixture identifiers are reused without a second stock deduction, redemption, or outbox event.

## Inspect the local dataset without changing it

These commands query only this project's `ecommerce_order_api` development database. They do not touch `postgres-local`, the guarded test database, or another project's services. Counts are scoped to the demo identifiers; manual changes or separately seeded fixtures can change them.

```bash
docker compose exec -T postgres psql -U ecommerce -d ecommerce_order_api -v ON_ERROR_STOP=1 <<'SQL'
BEGIN TRANSACTION READ ONLY;
SELECT 'users' AS table_name, count(*) AS demo_rows FROM users WHERE email LIKE 'demo-%@example.test'
UNION ALL SELECT 'products', count(*) FROM products WHERE lower(btrim(sku)) LIKE 'demo-v1-%'
UNION ALL SELECT 'promotions', count(*) FROM promotions WHERE code LIKE 'DEMO-V1-%'
UNION ALL SELECT 'carts', count(*) FROM carts c JOIN users u ON u.id = c.user_id WHERE u.email LIKE 'demo-%@example.test'
UNION ALL SELECT 'cart_items', count(*) FROM cart_items ci JOIN carts c ON c.id = ci.cart_id JOIN users u ON u.id = c.user_id WHERE u.email LIKE 'demo-%@example.test'
UNION ALL SELECT 'orders', count(*) FROM orders WHERE idempotency_key LIKE 'demo-v1:%'
UNION ALL SELECT 'order_items', count(*) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.idempotency_key LIKE 'demo-v1:%'
UNION ALL SELECT 'promotion_redemptions', count(*) FROM promotion_redemptions pr JOIN orders o ON o.id = pr.order_id WHERE o.idempotency_key LIKE 'demo-v1:%'
UNION ALL SELECT 'order_outbox_events', count(*) FROM order_outbox_events e JOIN orders o ON o.id = e.order_id WHERE o.idempotency_key LIKE 'demo-v1:%'
UNION ALL SELECT 'order_notifications', count(*) FROM order_notifications n JOIN orders o ON o.id = n.order_id WHERE o.idempotency_key LIKE 'demo-v1:%';
SELECT sku, price_minor, stock_quantity, status FROM products WHERE lower(btrim(sku)) LIKE 'demo-v1-%' ORDER BY sku;
SELECT u.email, o.id, o.idempotency_key, o.status, o.subtotal_minor, o.discount_minor, o.total_minor,
       o.placed_at, o.cancelled_at, o.inventory_restored_at
FROM orders o JOIN users u ON u.id = o.user_id WHERE o.idempotency_key LIKE 'demo-v1:%' ORDER BY u.email, o.id;
SELECT o.idempotency_key, e.id, e.event_type, e.status, e.dispatch_attempts, e.processing_attempts,
       e.dispatch_token, e.occurred_at, e.processed_at
FROM order_outbox_events e JOIN orders o ON o.id = e.order_id
WHERE o.idempotency_key LIKE 'demo-v1:%' ORDER BY o.idempotency_key, e.event_type;
SELECT p.code, count(pr.id) AS consumed_usage FROM promotions p
LEFT JOIN promotion_redemptions pr ON pr.promotion_id = p.id
WHERE p.code LIKE 'DEMO-V1-%' GROUP BY p.code ORDER BY p.code;
SELECT o.idempotency_key, o.subtotal_minor = sum(oi.line_subtotal_minor) AS snapshot_sum_valid,
       bool_and(oi.line_subtotal_minor = oi.unit_price_minor * oi.quantity) AS line_money_valid,
       o.total_minor = o.subtotal_minor - o.discount_minor AS total_valid
FROM orders o JOIN order_items oi ON oi.order_id = o.id WHERE o.idempotency_key LIKE 'demo-v1:%'
GROUP BY o.id, o.idempotency_key ORDER BY o.idempotency_key;
COMMIT;
SQL
```

Capture the output, rerun the demo seeder, and execute the same read-only query again. Unchanged counts, product stock, order snapshots, redemption usage, and event identities demonstrate repeatability. Normal API requests made between runs may legitimately change current carts, stock, or outbox processing state.

## Authenticate and use Postman

Import the existing collection and environment as described in the [Postman guide](../../postman/README.md). Keep the configured demo password and captured tokens in private local environment values.

- Administrator: set `admin_email=demo-admin@example.test` and `admin_password` to the configured password, then send **Authentication → Login admin**. Skip administrator registration and CLI role grants for this seeded account.
- Customer: set `customer_email=demo-lina@example.test` and `customer_password` to the configured password, then send **Authentication → Login customer**. Read the existing cart before editing it. Use Alex/Maya/Omar to inspect the corresponding order histories, or Noah for the low-stock cart.
- Optional role boundaries: log in the product and promotion managers separately and copy each returned token into the matching private manager-token variable.
- Find a product by its returned `sku` in public or administrator listings and use the actual returned `id`. Preserve the collection's dynamic captures; no fixture assumes a database primary key. Set a chosen demo `promotion_code` for manual promotion requests and locate historical `order_id` values from that customer's order history.
- Clear `idempotency_key` for a new manual purchase; preserve it for its replay. Do not reuse the seeder's historical purchase keys for different purchases.

The login contract is unchanged:

```json
{
  "email": "demo-lina@example.test",
  "password": "<your private configured demo password>",
  "device_name": "local-demo"
}
```

Send it to the existing `POST /api/auth/login` request. The response provides `data.user` and `data.token`; authenticated requests use that bearer token. Seeding creates no tokens beforehand. Registration scripts intentionally generate fresh credentials, so skip registration when using an existing demo account. The ordered **Complete E-Commerce Workflow** can reuse the seeded administrator credentials, while continuing to generate its own fresh customer, product, promotion, and purchase. Its independent fixtures do not overwrite the `DEMO-V1-` catalogue.

## Automated PostgreSQL verification

The existing test configuration refuses unexpected database and Redis targets before refreshing tables. Use the repository's Compose API runtime and its separate test database:

```bash
docker compose exec -T api php artisan test --compact tests/Feature/Database/Seeders
docker compose exec -T api php artisan test --compact
docker compose exec -T api php artisan test --compact \
  tests/Feature/Services/Cart/CartConcurrencyTest.php \
  tests/Feature/Services/Cart/CartPromotionConcurrencyTest.php \
  tests/Feature/Services/Checkout/CheckoutConcurrencyTest.php \
  tests/Feature/Services/Order/OrderConcurrencyTest.php \
  tests/Feature/Services/Admin/AdminConcurrencyTest.php \
  tests/Feature/Services/Order/OrderOutboxConcurrencyTest.php \
  tests/Feature/Http/Middleware/RateLimitConcurrencyTest.php
```

Tests create and refresh only `ecommerce_order_api_test`, with separate Redis databases and per-test namespaces. Existing database volumes must already contain that test database; see the main installation guide. Do not replace this setup with `migrate:fresh` on development or delete persistent volumes. A disposable Compose project with private services, tmpfs database storage, no published ports, service hosts `postgres` / `redis`, and database `ecommerce_order_api_test` is also suitable; its API should bind this checkout and use the same installed runtime image. Run tests serially because the guards and existing child-process fixtures require the exact test database name.
