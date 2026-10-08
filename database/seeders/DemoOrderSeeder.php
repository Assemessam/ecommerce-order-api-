<?php

namespace Database\Seeders;

use App\Enums\OrderEventType;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderOutboxEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartPromotionService;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use LogicException;

class DemoOrderSeeder extends DemoSeeder
{
    public function __construct(
        private CartService $carts,
        private CartPromotionService $promotions,
        private CheckoutService $checkout,
        private OrderService $orders,
    ) {}

    protected function seed(): void
    {
        /** @var list<array{key: string, email: string, items: array<string, int>, promotion: ?string}> $scenarios */
        $scenarios = [
            ['key' => 'standard', 'email' => 'demo-alex@example.test', 'items' => ['DEMO-V1-KEYBOARD' => 1, 'DEMO-V1-MOUSE' => 2], 'promotion' => null],
            ['key' => 'percentage', 'email' => 'demo-alex@example.test', 'items' => ['DEMO-V1-HEADPHONES' => 1], 'promotion' => 'DEMO-V1-WELCOME10'],
            ['key' => 'cancelled', 'email' => 'demo-maya@example.test', 'items' => ['DEMO-V1-MUG' => 2], 'promotion' => 'DEMO-V1-WELCOME10'],
            ['key' => 'fixed', 'email' => 'demo-maya@example.test', 'items' => ['DEMO-V1-STAND' => 1, 'DEMO-V1-CABLE' => 2], 'promotion' => 'DEMO-V1-SAVE5'],
            ['key' => 'historical', 'email' => 'demo-omar@example.test', 'items' => ['DEMO-V1-BACKPACK' => 1, 'DEMO-V1-NOTEBOOK' => 3], 'promotion' => null],
            ['key' => 'limited', 'email' => 'demo-omar@example.test', 'items' => ['DEMO-V1-ORGANIZER' => 2], 'promotion' => 'DEMO-V1-LIMITED5'],
            ['key' => 'capped', 'email' => 'demo-omar@example.test', 'items' => ['DEMO-V1-MONITOR' => 1], 'promotion' => 'DEMO-V1-CAPPED20'],
        ];

        foreach ($scenarios as $scenario) {
            $user = User::query()->where('email', $scenario['email'])->firstOrFail();
            $key = 'demo-v1:'.$scenario['key'];

            if (Order::query()->whereBelongsTo($user)->where('idempotency_key', $key)->exists()) {
                continue;
            }

            $cart = Cart::query()->whereBelongsTo($user)->lockForUpdate()->first();

            if ($cart !== null && ($cart->items()->exists() || $cart->promotion_id !== null)) {
                throw new LogicException('Refusing to check out an existing basket for '.$user->email);
            }

            foreach ($scenario['items'] as $sku => $quantity) {
                $product = Product::query()->whereRaw('lower(btrim(sku)) = ?', [strtolower($sku)])->firstOrFail();
                $this->carts->addItem($user, $product->id, $quantity);
            }

            if ($scenario['promotion'] !== null) {
                $this->promotions->apply($user, $scenario['promotion']);
            }

            $order = $this->checkout->checkout($user, $key)->order;

            if ($scenario['key'] === 'cancelled') {
                $this->orders->cancelOrder($user, (string) $order->id);
            }

            if ($scenario['key'] === 'historical') {
                $this->dateHistoricalPurchase($order);
            }
        }
    }

    /** The checkout API has no historical-date input; only fresh fixture timestamps are adapted. */
    private function dateHistoricalPurchase(Order $order): void
    {
        $placedAt = now('UTC')->subDays(45);
        $formattedPlacedAt = $placedAt->format('Y-m-d H:i:s.uP');
        $timestamps = ['created_at' => $placedAt->format('Y-m-d H:i:sP'), 'updated_at' => $placedAt->format('Y-m-d H:i:sP')];
        $order->forceFill(['placed_at' => $placedAt, ...$timestamps])->save();
        $order->items()->update($timestamps);

        OrderOutboxEvent::query()->where('order_id', $order->id)->where('event_type', OrderEventType::OrderPlaced)
            ->update(['occurred_at' => $formattedPlacedAt, 'created_at' => $formattedPlacedAt, 'updated_at' => $formattedPlacedAt]);
    }
}
