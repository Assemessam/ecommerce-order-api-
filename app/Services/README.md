# Application services

Place business use cases here, grouped by domain. Services coordinate repositories and own transaction boundaries for multi-step workflows such as checkout and cancellation.

`ProductService` is the shared public/administrative product application service. Public `listProducts` / `getProduct` retain active visibility; actor-aware `listAdminProducts`, `getAdminProduct`, `createProduct`, and `updateProduct` enforce ProductPolicy safeguards, including conditional inventory permission. `ProductCatalogueCache` remains its separate public-listing collaborator. Checkout deductions and cancellation restoration remain in their owning services.

`RoleProvisioningService` coordinates local-only canonical-role grants/revocations through the existing User repository and Spatie APIs. Policies check permissions; services do not check role names. See [the refactor report](../../docs/19-product-service-rbac-refactor.md).
