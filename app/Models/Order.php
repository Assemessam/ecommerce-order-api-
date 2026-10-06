<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PromotionType;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'total_minor', 'promotion_id', 'promotion_code_snapshot', 'promotion_type_snapshot', 'promotion_value_snapshot', 'promotion_maximum_discount_minor_snapshot', 'idempotency_key', 'placed_at'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasOne<PromotionRedemption, $this> */
    public function redemption(): HasOne
    {
        return $this->hasOne(PromotionRedemption::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'promotion_type_snapshot' => PromotionType::class,
            'promotion_value_snapshot' => 'integer',
            'promotion_maximum_discount_minor_snapshot' => 'integer',
            'placed_at' => 'immutable_datetime',
        ];
    }
}
