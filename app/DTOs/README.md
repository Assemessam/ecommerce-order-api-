# Data transfer objects

Use immutable DTOs at service boundaries when a use case has a meaningful multi-field input or result. Do not mirror every model mechanically.

The typed application-input refactor (`16faf77`) added these seven `final readonly` DTOs:

| DTO | Service boundary |
| --- | --- |
| [Auth/RegisterUserData](Auth/RegisterUserData.php) | `AuthService::register()` |
| [Auth/LoginData](Auth/LoginData.php) | `AuthService::login()` |
| [Product/CreateProductData](Product/CreateProductData.php) | `ProductService::createProduct()` |
| [Product/UpdateProductData](Product/UpdateProductData.php) | `ProductService::updateProduct()` |
| [Promotion/CreatePromotionData](Promotion/CreatePromotionData.php) | `PromotionAdministrationService::createPromotion()` |
| [Promotion/UpdatePromotionData](Promotion/UpdatePromotionData.php) | `PromotionAdministrationService::updatePromotion()` |
| [Promotion/PromotionQuery](Promotion/PromotionQuery.php) | `PromotionAdministrationService::listPromotions()` |

Controllers create these inputs from validated Form Request data. DTOs enforce field types and retain only supported fields; update DTOs distinguish omitted fields from explicit nullable clears. Form Requests retain HTTP validation, while services retain authorization, business rules, and transactions. Persistence mapping happens inside the service boundary without changing repository interfaces. Existing query/result DTOs remain in use; scalar cart, checkout, and order inputs are unchanged.
