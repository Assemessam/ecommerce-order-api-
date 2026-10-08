<?php

namespace Database\Seeders;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartPromotionService;
use App\Services\Cart\CartService;

class DemoCartSeeder extends DemoSeeder
{
    public function __construct(
        private CartRepositoryInterface $carts,
        private CartService $cartService,
        private CartPromotionService $promotions,
    ) {}

    protected function seed(): void
    {
        /** @var list<array{email: string, items: array<string, int>, promotion: ?string}> $scenarios */
        $scenarios = [
            ['email' => 'demo-lina@example.test', 'items' => ['DEMO-V1-KEYBOARD' => 1, 'DEMO-V1-HUB' => 1], 'promotion' => null],
            ['email' => 'demo-noah@example.test', 'items' => ['DEMO-V1-LIMITED' => 1], 'promotion' => 'DEMO-V1-SAVE5'],
        ];

        foreach ($scenarios as $scenario) {
            $user = User::query()->where('email', $scenario['email'])->firstOrFail();

            if (Cart::query()->whereBelongsTo($user)->exists()) {
                continue;
            }

            $this->carts->createOrLockForUser($user);

            foreach ($scenario['items'] as $sku => $quantity) {
                $product = Product::query()->whereRaw('lower(btrim(sku)) = ?', [strtolower($sku)])->firstOrFail();
                $this->cartService->addItem($user, $product->id, $quantity);
            }

            if ($scenario['promotion'] !== null) {
                $this->promotions->apply($user, $scenario['promotion']);
            }
        }
    }
}
