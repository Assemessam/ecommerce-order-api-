# Postman workspace

Import [the collection](Ecommerce_Order_Promotion_API.postman_collection.json) and [the local Docker environment](Ecommerce_Local_Docker.postman_environment.json), then select **Ecommerce Local Docker** in Postman. The collection has 12 folders and 80 requests covering all 25 implemented `/api` operations. The **Complete E-Commerce Workflow** folder provides an ordered run after local administrator provisioning.

## 1. Start the application

Run commands from the repository root. For a new installation, this sequence preserves an existing `.env` and generates an application key only when it creates that file:

```bash
cd /var/www/ecommerce-order-api
postman_created_env=0
if [ ! -f .env ]; then
    cp .env.example .env
    postman_created_env=1
fi
docker compose build api
docker compose run --rm api composer install --no-interaction
if [ "$postman_created_env" = 1 ]; then
    docker compose run --rm api php artisan key:generate --no-interaction
fi
docker compose run --rm api php artisan migrate --no-interaction
docker compose up -d api
```

If an existing `.env` has an empty `APP_KEY`, generate its key once with `docker compose run --rm api php artisan key:generate --no-interaction`. Preserve an existing nonempty key. Existing installations with dependencies and a key already configured can use:

```bash
docker compose up -d api
docker compose exec -T api php artisan migrate --no-interaction
docker compose exec -T api php artisan route:list --path=api --except-vendor --no-interaction
curl -H 'Accept: application/json' http://localhost:8091/api/health
```

The health response is `{"data":{"status":"ok"}}`. Compose starts this project's PostgreSQL and Redis services as dependencies. Its HTTP mapping is `${APP_PORT:-8091}:8000`; the imported `base_url` is `http://localhost:8091`, without `/api` or a trailing slash. If you configured another `APP_PORT`, update `base_url` and `APP_URL` accordingly. Use the host-facing HTTP port, not PostgreSQL's forwarded port or a Docker service hostname. `localhost` targets the machine running Postman's desktop agent; use your Docker host's reachable address when it is remote.

The workflow creates its own products and promotions, so seeding is unnecessary. Additive `migrate` is sufficient to bootstrap the internal roles and permissions. Do not reset the database or remove persistent volumes for Postman testing. The default `DatabaseSeeder` restores only the canonical authorization definitions and creates no accounts. Optional sample catalogue fixtures, if wanted for manual browsing, are:

```bash
docker compose exec -T api php artisan db:seed --class=ProductSeeder --no-interaction
docker compose exec -T api php artisan db:seed --class=PromotionSeeder --no-interaction
```

Order placement and cancellation work without a queue worker. To process the transactional outbox and order notifications as well, enable the existing optional services:

```bash
docker compose --profile orders up -d api order-worker order-scheduler
```

On Linux, the bind-mounted files must be writable by the configured container user. The Compose default is UID/GID 1000; the main [README](../README.md#installation-and-database-setup) explains overriding it for setup commands.

## 2. Import and provision an administrator

1. In Postman, use **Import** for both JSON files above.
2. Select the imported environment. Keep generated passwords and tokens in your local environment values; do not share or commit an export containing them.
3. Send **Health** to confirm the API is reachable.
4. Send **Authentication → Register admin setup account** once. With blank `admin_email` and `admin_password`, its script generates dedicated local credentials. It preserves nonblank values so the email used by the CLI remains stable. Registration alone creates an ordinary customer account.
5. Copy the generated `admin_email` value from the selected environment into this command, replacing the example email:

```bash
docker compose exec -T api php artisan roles:grant your-generated-admin@example.test administrator --no-interaction
# Equivalent compatibility alias:
# docker compose exec -T api php artisan admin:grant your-generated-admin@example.test --no-interaction
```

6. Send **Authentication → Login admin**. Its response stores `admin_token`; **Current admin** confirms the account identity, while an admin list request confirms authorization.

These commands require `APP_ENV=local`, accept an existing account, and create no password or token. They are idempotent. No role-assignment HTTP API exists. Existing bearer tokens reflect current roles, but logging in after granting a role makes the setup sequence straightforward. Do not repeatedly send administrator registration with the same email: that returns 422. To use an already provisioned administrator, set its private credentials locally and send **Login admin** directly.

For optional role-boundary checks, register two separate local test accounts, then grant their corresponding roles:

```bash
docker compose exec -T api php artisan roles:grant your-product-manager@example.test product_manager --no-interaction
docker compose exec -T api php artisan roles:grant your-promotion-manager@example.test promotion_manager --no-interaction
# Revoke a role when finished:
docker compose exec -T api php artisan roles:revoke your-product-manager@example.test product_manager --no-interaction
```

Log in each account and save its returned `data.token` into the matching local `product_manager_token` or `promotion_manager_token`, then use **Optional RBAC Boundaries**. Product Manager can read/create/update products and adjust inventory; Promotion Manager can read/create/update promotions. Administrator has both sets of permissions. The two optional 403 checks require separate accounts with only the stated role; combined roles correctly grant both sets. Staff still cannot access another customer's cart or orders.

### Use optional seeded accounts

The [local seeding guide](../database/seeders/README.md) provides an explicitly opt-in `DemoDatabaseSeeder` with staff accounts, customers, a catalogue, promotions, carts, and order history. Configure a private `DEMO_SEED_PASSWORD` in `.env` and follow its Docker commands. The password is used only when an account is first created; rerunning the seeder does not reset existing passwords or roles.

For the seeded administrator, set local `admin_email` to `demo-admin@example.test`, set `admin_password` to your configured password, and send **Login admin**. Skip **Register admin setup account** and the CLI grant for that already provisioned account. For an existing demo customer, set `customer_email` to `demo-lina@example.test` or another documented identity, set `customer_password`, and send **Login customer**, skipping registration. Lina's cart contains two available products; Noah's contains a low-stock tripod and an eligible fixed promotion. Alex, Maya, and Omar provide committed order histories.

Locate products by their `DEMO-V1-` SKUs in returned listings and use their actual IDs; preserve the collection's dynamic captures. Manual promotion examples can use `DEMO-V1-WELCOME10` or `DEMO-V1-SAVE5`; `DEMO-V1-EXPIRED10` is available for an explicitly ineligible scenario. The seeded manager accounts can supply the two optional role-boundary tokens. The ordered workflow may reuse the seeded administrator credentials and continues to generate its own customer, products, promotions, and purchase. Its registration script replaces customer credentials intentionally, so use individual login requests when inspecting existing demo customers. No collection or environment contract changes are required.

## 3. Run the ordered workflow

Open **Complete E-Commerce Workflow** in the Collection Runner, select the environment, use one iteration, and run its 29 requests in their displayed order. Provision the administrator first; keep its saved credentials for the initial admin login. Do not run the entire collection as one unattended sequence: manual examples include updates that deactivate resources, and the separate negative folder intentionally exercises failures.

The workflow checks health, logs in the administrator, registers a fresh customer, creates an active stocked product and an eligible percentage promotion, then exercises cart add/read/update/delete/re-add and promotion apply/remove/reapply. It checks out with a new key, replays the same purchase, verifies the emptied cart, reads order history/detail, cancels the order, retries cancellation, verifies inventory restoration and retained coupon usage, and logs out the customer. Product stock reads use the authoritative admin endpoint. The workflow keeps the administrator account, and creates new customer/product/promotion/order records for each run; there is no cleanup that deletes shared records.

Registration generates new `customer_email` and `customer_password` on every send, including each workflow iteration. **Login customer** reuses the latest saved credentials. To log in an existing customer, enter that customer's credentials locally and send **Login customer**, skipping registration. Default registration is limited to **five attempts per IP per hour**, including unsuccessful attempts; administrator setup and any other local registrations share that allowance. Use one workflow iteration, respect `Retry-After` after 429, and avoid rapid repeated runs. The collection does not deliberately flood the limiter.

## 4. Use individual folders

| Folder | Prerequisites and sequence |
|---|---|
| Health | Public; send before starting authenticated testing. |
| Authentication | Register or log in; customer responses store `customer_token`, admin setup/login responses store `admin_token`. Current-user and logout requests use their stated token. Logout revokes the current token only. |
| Public Products | Public listing can capture an active stocked `product_id` when one is returned. An empty catalogue does not provide an ID; create a product through the admin folder first. Detail requires `product_id`. |
| Cart | Requires `customer_token` and an active stocked `product_id`. POST adds to any existing quantity; PATCH replaces quantity. A captured `cart_item_id` identifies a cart line, not a product. DELETE returns 204; add an item again before checkout. |
| Cart Promotions | Requires a nonempty purchasable cart and an active eligible `promotion_code`. Apply returns the cart with estimates; remove returns 204. |
| Checkout | Requires customer authentication and a purchasable cart. Checkout is bodyless and sends `Idempotency-Key`. A new purchase returns 201; replay of the same customer/key returns 200 with the original order. |
| Orders | History requires customer authentication; detail/cancel require that customer's captured `order_id`. Cancellation and its retry both return 200. |
| Admin — Products | Requires `admin_token` with product permissions. Create stores both admin and customer product IDs. PATCH demonstrates price/status changes and a signed stock delta. |
| Admin — Promotions | Requires `admin_token` with promotion permissions. Create percentage or fixed promotion stores `admin_promotion_id` and `promotion_code`. PATCH demonstrates conversion to fixed and deactivation. |
| Negative / Error Scenarios | Run after a completed workflow with its product/promotion still unchanged. Includes customer login, owned-line setup, a separate expired-coupon fixture, and removal of that owned line. Failed requests do not capture success variables. |
| Optional RBAC Boundaries | Two read-only 403 checks; requires separate `product_manager_token` and `promotion_manager_token` values. |
| Complete E-Commerce Workflow | One ordered 29-request journey; requires local administrator provisioning and saved admin credentials. |

For a new manual purchase, **clear `idempotency_key` before Checkout** so its script generates a new key. Keep the same key for **Replay checkout with same key** or an uncertain network result. Reusing an old successful key returns its order even when the current cart contains new items. Do not clear the key between checkout and replay. The workflow deliberately starts each run with a new purchase key.

Cart estimates do not reserve inventory; checkout recalculates availability, prices, and promotion eligibility. Manual Checkout validates the returned order without requiring equality to an earlier cart estimate; the controlled workflow also checks its fresh cart estimate against the purchase total. Cancellation changes `placed` to `cancelled` and restores stock exactly once. Repeated cancellation returns the same cancelled order without a second restoration. Historical totals, promotion snapshots, and consumed promotion redemptions remain; cancellation does not refund promotion usage.

Admin PATCH requests are partial updates. Product PATCH uses `stock_adjustment`, a signed nonzero delta; it cannot set absolute `stock_quantity`. The example that makes a product inactive will hide it from public reads and prevent purchase. Stock deltas are additive and **not idempotent**: sending the update again applies the delta again. Refresh stock after an uncertain response before deciding whether to send another adjustment. Promotion updates affect future eligibility and preserve past orders and consumed usage. Non-null usage limits cannot be reduced below consumed counts. Admin resources are retired through deactivation; no product/promotion hard-delete endpoints exist.

For **Update admin product and adjust stock**, send **Get admin product** immediately beforehand to capture a current stock baseline. When `admin_product_id` matches the selected `product_id`, the PATCH script checks the example's `+3` delta and refreshes `product_stock_quantity` from the successful response.

## 5. Payloads and response handling

All applicable requests contain complete JSON examples. Their variable-backed numeric fields are unquoted JSON numbers, not numeric strings. Requests without accepted body fields have no invented payload.

| Endpoint accepting JSON | Example contract and dynamic values |
|---|---|
| `POST /api/auth/register` | Name, generated unique email, generated strong password, matching confirmation, and optional device name. Customer generation is fresh each send; administrator generation fills blanks only. |
| `POST /api/auth/login` | Saved email/password and optional device name. Credentials are JSON-escaped by scripts so quotes or backslashes in a private password remain valid. |
| `POST /api/cart/items` | Captured positive integer `product_id` and quantity `1`; the PATCH example sets `cart_quantity=2`. |
| `PATCH /api/cart/items/{id}` | Positive integer replacement quantity for the captured line. |
| `POST /api/cart/promotion` | Captured promotion `code`. |
| `POST /api/admin/products` | Name, unique generated SKU, description, integer `price_minor`, initial integer `stock_quantity`, and `active` status. |
| `PATCH /api/admin/products/{id}` | Partial name/description/price/status edits plus signed nonzero `stock_adjustment`; no absolute stock field. |
| `POST /api/admin/promotions` | Unique uppercase code; `percentage` or `fixed` type; integer value; minimum amount, cap, usage limits, dynamically generated validity dates, and actual boolean `is_active`. |
| `PATCH /api/admin/promotions/{id}` | Partial edits validated with retained fields; the example supplies a fixed integer value and disables the promotion. |

`_minor` values are integer minor currency units: with the default USD label, `1299` means $12.99. Promotion percentages use **basis points**, so `1000` means 10% and `2000` means 20%; the permitted percentage range is 1–10000. Fixed promotion values and caps are minor units. The ordinary create examples generate ISO 8601 dates at whole-second precision from one hour ago to seven days ahead; the separate negative fixture uses dates from two days ago to one day ago. Use actual JSON booleans for promotion `is_active`.

The API supports signed 64-bit integer bounds; ordinary Postman JavaScript numbers are exact only through `Number.MAX_SAFE_INTEGER` (2^53−1). The examples and assertions use safe integers. For larger application values, use a client with lossless JSON integer handling rather than interpreting a rounded value in this collection.

Success responses use a `data` envelope. Lists also have `links` and `meta`; product money is `data.price.amount_minor`, cart money is under `subtotal`, `estimated_discount`, and `estimated_total`, and order money is under `subtotal`, `discount`, and `total`. Authentication returns `data.user` and `data.token`. Post-response scripts assert the actual status/resource fields, totals and IDs, then capture successful validated values. Empty 204 responses are checked without parsing JSON. Failed or malformed responses do not replace saved values with null/undefined.

Errors use `error.code`, `error.message`, and `error.request_id`; validation field messages are under `error.details.fields`. Requests inherit bearer authorization from customer/admin folders where appropriate; public/auth requests disable it. Cookie sessions and CSRF setup are unnecessary.

## 6. Variable lifecycle and privacy

The committed environment has 29 variables: the local HTTP URL, safe quantity default, and blank runtime values. Password/token entries have type `secret`. Scripts use `pm.environment` for the selected environment and `pm.variables` for temporary helpers prepared for each request, including JSON-escaped credential values such as `customer_password_json`; these are not saved collection variables and need no manual configuration. Ordinary promotion dates are generated into the environment; negative fixture dates and keys are temporary. Select the imported environment before sending requests. These scopes follow [Postman's variable API reference](https://learning.postman.com/docs/tests-and-scripts/write-scripts/postman-sandbox-reference/pm-variables).

| Environment variable(s) | Source | Lifecycle / purpose |
|---|---|---|
| `base_url` | Manual configuration; default `http://localhost:8091` | Host-facing API URL without `/api`. |
| `customer_email`, `customer_password` | Registration script or manually supplied existing credentials | Fresh customer generation on each registration; login uses the latest saved values. Password is a secret. |
| `admin_email`, `admin_password` | Admin setup script fills blanks, or manual existing credentials | Preserve for CLI provisioning and later login. Password is a secret. |
| `customer_token`, `admin_token` | Successful registration/login response | Bearer secrets; customer logout clears the current customer token. |
| `customer_id`, `admin_id` | Successful corresponding authentication response | Checks ownership/identity in subsequent responses. |
| `product_id`, `admin_product_id`, `product_sku`, `product_price_minor` | Successful listing/detail/create; SKU generated before create | Customer purchase target and admin mutation target; refreshed for a new workflow. |
| `product_stock_quantity` | Successful selected-product listing/detail/create and matching admin read/update | Current stock capture; refresh before PATCH or an insufficient-stock scenario. |
| `initial_stock_quantity` | Successful product creation | Baseline for workflow checkout deduction and exactly-once restoration assertions. |
| `cart_quantity` | Manual; default `2`, reset to `2` on customer registration | Safe integer quantity for the cart examples. |
| `cart_item_id` | Successful cart response for the selected product | PATCH/DELETE target; refreshed when a line is recreated. |
| `cart_estimated_total_minor` | Successful cart response | Current cart estimate; cleared after cart-item or promotion DELETE and refreshed by the next cart response. Kept separately from the saved order total. |
| `promotion_code`, `admin_promotion_id` | Code generated before create, IDs/code captured after success | Apply/edit target; create examples generate a fresh code per send. |
| `promotion_starts_at`, `promotion_expires_at` | Ordinary promotion creation pre-request script | Eligible rolling date window; regenerated for each create example. |
| `expired_promotion_code` | Negative fixture generates a temporary code, captures it after successful creation | Used only by **Expired promotion**; preserves the main promotion variables. |
| `idempotency_key` | Generated when blank for manual checkout; new at workflow start | Preserve for a retry/replay; clear before a new purchase. |
| `order_id`, `checkout_total_minor` | Successful checkout response | Order identity/total for detail, cancellation and replay assertions. Cart responses never overwrite the saved order total. |
| `cancelled_at` | Successful cancellation response | Retry must preserve the original cancellation/restoration timestamp. |
| `product_manager_token`, `promotion_manager_token` | Manually copied login response for optional separate staff accounts | Secret tokens for optional permission-boundary checks. |

Save private runtime values only locally; do not publish them to shared Postman values. Inspect any exported environment, collection examples, console output, and runner report before sharing. Clear passwords and bearer tokens from exports; the committed import files never need an application key, database password, Redis credential, or session cookie.

## 7. Negative tests and limits

Run **Negative / Error Scenarios** after a successful complete workflow, before sending manual admin mutations or creating another selected promotion. Its 18 requests begin with **Authenticate customer for error scenarios**, because the workflow logged the customer out. The initial stock and empty-cart failures assume that customer's cart is still empty and the workflow product remains active; **Staff cannot read another customer order** uses the distinct administrator against the customer's saved order.

The folder then adds its own one-unit cart line. That gives the unknown/expired/consumed-coupon checks a nonempty purchasable cart: an unknown code on an empty cart would instead return `EMPTY_CART`. The consumed-coupon check uses the workflow promotion's per-customer limit of 1 and its retained redemption. The expired fixture is a new separate promotion, and preserves `promotion_code` / `admin_promotion_id`. The last request removes only the owned line created for these checks. Do not rerun that DELETE alone after success; the line no longer exists.

Missing/invalid authentication returns 401 `UNAUTHENTICATED`, customer admin access returns 403 `FORBIDDEN`, and missing/foreign resources return non-enumerating 404 `RESOURCE_NOT_FOUND`. Validation returns 422 `VALIDATION_FAILED` with field details. Insufficient stock returns 409 `INSUFFICIENT_STOCK`; empty checkout returns 409 `CART_EMPTY`; unknown promotion returns 404 `PROMOTION_NOT_FOUND`; expired promotion returns 422 `PROMOTION_EXPIRED`; exhausted customer usage returns 409 `PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED`.

Inactive/not-started/minimum-not-met promotions also return 422, and exhausted global usage returns 409, but these variants are not separate automatic collection scenarios. Ordinary cancellation retry is covered as a successful 200; an invalid order state is not manufactured through the supported API. No automated 429 flood is included; check rate-limit behavior only in a disposable isolated context and respect `Retry-After`. A Redis limiter outage returns 503 before business operations. Use each request's setup instructions, preserve the completed workflow context, and leave optional manager checks unselected until their tokens exist.

## 8. Endpoint coverage

This inventory follows `routes/api.php` and `php artisan route:list --path=api --except-vendor`. Controller names below are in `App\Http\Controllers\Api`; FormRequest names are relative to `App\Http\Requests`. “—” means the action accepts Laravel's generic `Request` or no request and has no dedicated FormRequest. GET routes also support HEAD. Framework `/up` and web routes are outside the application `/api` inventory.

| Method | API endpoint | Controller action | FormRequest | Primary Postman request | Complete? |
|---|---|---|---|---|---|
| GET | `/api/health` | `HealthController::__invoke` | — | Health → Health | Yes |
| POST | `/api/auth/register` | `AuthController::register` | `Auth/RegisterRequest` | Authentication → Register customer | Yes |
| POST | `/api/auth/login` | `AuthController::login` | `Auth/LoginRequest` | Authentication → Login customer | Yes |
| GET | `/api/auth/me` | `AuthController::me` | — | Authentication → Current customer | Yes |
| POST | `/api/auth/logout` | `AuthController::logout` | — | Authentication → Logout customer | Yes |
| GET | `/api/products` | `ProductController::index` | `Product/ProductQueryRequest` | Public Products → List products | Yes |
| GET | `/api/products/{id}` | `ProductController::show` | — | Public Products → Product details | Yes |
| GET | `/api/cart` | `CartController::show` | — | Cart → Retrieve cart | Yes |
| POST | `/api/cart/items` | `CartController::store` | `Cart/AddCartItemRequest` | Cart → Add cart item | Yes |
| PATCH | `/api/cart/items/{id}` | `CartController::update` | `Cart/UpdateCartItemRequest` | Cart → Update cart item | Yes |
| DELETE | `/api/cart/items/{id}` | `CartController::destroy` | — | Cart → Delete cart item | Yes |
| POST | `/api/cart/promotion` | `CartPromotionController::store` | `Cart/ApplyPromotionRequest` | Cart Promotions → Apply promotion | Yes |
| DELETE | `/api/cart/promotion` | `CartPromotionController::destroy` | — | Cart Promotions → Remove promotion | Yes |
| POST | `/api/checkout` | `CheckoutController::__invoke` | `Checkout/CheckoutRequest` | Checkout → Checkout | Yes |
| GET | `/api/orders` | `OrderController::index` | `Order/OrderQueryRequest` | Orders → Order history | Yes |
| GET | `/api/orders/{id}` | `OrderController::show` | — | Orders → Order details | Yes |
| POST | `/api/orders/{id}/cancel` | `OrderController::cancel` | — | Orders → Cancel order | Yes |
| GET | `/api/admin/products` | `AdminProductController::index` | `Admin/ProductQueryRequest` | Admin — Products → List admin products | Yes |
| GET | `/api/admin/products/{id}` | `AdminProductController::show` | — | Admin — Products → Get admin product | Yes |
| POST | `/api/admin/products` | `AdminProductController::store` | `Admin/ProductStoreRequest` | Admin — Products → Create admin product | Yes |
| PATCH | `/api/admin/products/{id}` | `AdminProductController::update` | `Admin/ProductUpdateRequest` | Admin — Products → Update admin product and adjust stock | Yes |
| GET | `/api/admin/promotions` | `AdminPromotionController::index` | `Admin/PromotionQueryRequest` | Admin — Promotions → List admin promotions | Yes |
| GET | `/api/admin/promotions/{id}` | `AdminPromotionController::show` | — | Admin — Promotions → Get admin promotion | Yes |
| POST | `/api/admin/promotions` | `AdminPromotionController::store` | `Admin/PromotionStoreRequest` | Admin — Promotions → Create percentage promotion / Create fixed promotion | Yes |
| PATCH | `/api/admin/promotions/{id}` | `AdminPromotionController::update` | `Admin/PromotionUpdateRequest` | Admin — Promotions → Update admin promotion | Yes |

Read the request descriptions for query filters, expected success statuses, side effects, and subsequent requests. The coverage table records representation in the collection; the completion report separately records which checks and runtime requests were actually executed.
