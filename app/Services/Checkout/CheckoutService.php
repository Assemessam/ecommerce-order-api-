<?php

namespace App\Services\Checkout;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\DTOs\Cart\CartLine;
use App\DTOs\Checkout\CheckoutResult;
use App\Enums\ProductStatus;
use App\Enums\PromotionIneligibilityReason;
use App\Exceptions\Domain\CheckoutConflictException;
use App\Exceptions\Domain\EmptyCartException;
use App\Exceptions\Domain\InactiveProductException;
use App\Exceptions\Domain\InsufficientStockException;
use App\Exceptions\Domain\PromotionNotEligibleException;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartPricingService;
use App\Services\Product\ProductCatalogueCache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private ProductRepositoryInterface $products,
        private PromotionRepositoryInterface $promotions,
        private OrderRepositoryInterface $orders,
        private CartPricingService $pricing,
        private ProductCatalogueCache $catalogueCache,
    ) {}

    public function checkout(User $user, ?string $idempotencyKey = null): CheckoutResult
    {
        $redemptionKey = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($user, $idempotencyKey, $redemptionKey): CheckoutResult {
                $cart = $this->carts->lockForUser($user);

                if ($cart !== null) {
                    Gate::forUser($user)->authorize('update', $cart);
                }

                if ($idempotencyKey !== null) {
                    $previousOrder = $this->orders->findByIdempotencyKey($user, $idempotencyKey);

                    if ($previousOrder !== null) {
                        return new CheckoutResult($previousOrder, isReplay: true);
                    }
                }

                if ($cart === null || $this->carts->loadCheckoutItems($cart)->items->isEmpty()) {
                    throw new EmptyCartException;
                }

                $products = $this->products->lockByIds($cart->items->pluck('product_id')->all());

                foreach ($cart->items as $item) {
                    $product = $products->get($item->product_id);

                    if ($product === null) {
                        throw (new ModelNotFoundException)->setModel(Product::class, [$item->product_id]);
                    }

                    if ($product->status !== ProductStatus::Active) {
                        throw new InactiveProductException;
                    }

                    if ($item->quantity > $product->stock_quantity) {
                        throw new InsufficientStockException($product->id, $product->stock_quantity);
                    }

                    $item->setRelation('product', $product);
                }

                $view = $this->pricing->summarize($cart, includePromotion: false);

                if ($cart->promotion_id !== null) {
                    $promotion = $this->promotions->findByIdForUpdate($cart->promotion_id);

                    if ($promotion === null) {
                        throw new PromotionNotEligibleException(PromotionIneligibilityReason::Unknown);
                    }

                    $view = $this->pricing->withPromotion($view, $promotion, $user->id);

                    if (! $view->promotionEligibility->isEligible()) {
                        throw new PromotionNotEligibleException($view->promotionEligibility->reason);
                    }
                }

                $order = $this->orders->create($user, $view, $idempotencyKey);
                $this->orders->createItems($order, $view->items);

                foreach ($view->items->sortBy(fn (CartLine $line): int => $line->product->id) as $line) {
                    if (! $this->products->deductStock($line->product, $line->quantity)) {
                        throw new InsufficientStockException($line->product->id, $line->product->stock_quantity);
                    }
                }

                if ($view->promotion !== null) {
                    $this->promotions->createRedemption($order, $redemptionKey);
                }

                $this->carts->clear($cart);
                $this->catalogueCache->invalidateAfterCommit();

                return new CheckoutResult($this->orders->loadItems($order));
            }, attempts: 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new CheckoutConflictException;
            }

            throw $exception;
        }
    }
}
