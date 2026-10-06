<?php

namespace App\Services\Cart;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\DTOs\Cart\CartView;
use App\Enums\PromotionIneligibilityReason;
use App\Exceptions\Domain\CartConflictException;
use App\Exceptions\Domain\PromotionNotEligibleException;
use App\Models\User;
use App\Services\Promotion\PromotionService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CartPromotionService
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private PromotionService $promotions,
        private CartPricingService $pricing,
    ) {}

    public function apply(User $user, string $code): CartView
    {
        return $this->mutate(function () use ($user, $code): CartView {
            $cart = $this->carts->lockForUser($user);

            if ($cart === null) {
                throw new PromotionNotEligibleException(PromotionIneligibilityReason::EmptyCart);
            }

            Gate::forUser($user)->authorize('update', $cart);
            $view = $this->pricing->summarize($this->carts->loadItems($cart), includePromotion: false);

            if ($view->items->isEmpty()) {
                throw new PromotionNotEligibleException(PromotionIneligibilityReason::EmptyCart);
            }

            $promotion = $this->promotions->resolveCode($code);
            $view = $this->pricing->withPromotion($view, $promotion, $user->id);

            if (! $view->promotionEligibility->isEligible()) {
                throw new PromotionNotEligibleException($view->promotionEligibility->reason);
            }

            $this->carts->setPromotion($cart, $promotion);

            return $view;
        });
    }

    public function remove(User $user): void
    {
        $this->mutate(function () use ($user): void {
            $cart = $this->carts->lockForUser($user);

            if ($cart !== null) {
                Gate::forUser($user)->authorize('update', $cart);
                $this->carts->setPromotion($cart, null);
            }
        });
    }

    /** @param Closure(): mixed $operation */
    private function mutate(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation, attempts: 3);
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (str_starts_with($sqlState, '23') || in_array($sqlState, ['40001', '40P01', '55P03'], true)) {
                throw new CartConflictException;
            }

            throw $exception;
        }
    }
}
