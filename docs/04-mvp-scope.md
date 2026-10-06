# 04 — MVP Scope

## In scope

- Customer registration/login/logout/current profile and Sanctum personal access tokens (implemented in Milestone 1).
- Public active-product list/detail with search, price filters, availability, sorting, and pagination.
- One cart per customer with add/update/remove/view behavior.
- One attached percentage or fixed promotion per cart.
- Transactional checkout with authoritative pricing, row locking, stock deduction, promotion usage enforcement, order/item creation, and cart clearing.
- Customer-owned order list/detail.
- Safe cancellation of eligible orders with exactly-once inventory restoration.
- Consistent validation/error responses, policies, database constraints, factories, seeders, and automated tests.
- Docker Compose development runtime with PostgreSQL.

## Explicitly out of scope

- Frontend, mobile client, or server-rendered UI.
- Admin/product/promotion/order-management APIs.
- Payment gateway, refunds, invoices, tax engine, shipping rates, fulfillment integration, or emails.
- Product variants, categories, images, bundles, wishlists, reviews, guest carts, multiple currencies, or multiple warehouses.
- Stacked promotions, product-specific promotions, gift cards, or reservation expiry.
- Microservices, queues required for the core transaction, Redis, Elasticsearch, and external infrastructure.
- Production deployment automation and monitoring platform integration.

## Delivery increments

1. Foundation and reviewed design documents (completed).
2. Authentication and shared API/error conventions (implemented; review checkpoint).
3. Product catalogue (implemented in Milestone 2; review checkpoint).
4. Cart.
5. Promotions.
6. Checkout and concurrency controls.
7. Orders and cancellation.
8. Cross-domain hardening, quality assurance, and submission packaging.

Each increment requires migrations, implementation, focused tests, formatting/static checks where configured, and a review checkpoint before the next domain begins.

## Definition of done for the foundation

- Laravel 13 and locked Composer dependencies install.
- Sanctum and Pest are installed.
- PostgreSQL configuration and Docker runtime are reproducible.
- Base migrations succeed against PostgreSQL.
- JSON health endpoint has an automated passing test.
- Architecture directories and binding convention are documented.
- All ten planning documents and project README exist.
- No business endpoint or domain implementation is included.
