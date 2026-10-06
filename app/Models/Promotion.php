<?php

namespace App\Models;

use App\Enums\PromotionType;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['code', 'type', 'value', 'minimum_cart_amount_minor', 'maximum_discount_minor', 'starts_at', 'expires_at', 'global_usage_limit', 'per_customer_usage_limit', 'is_active'])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Preserve offsets and microseconds when writing validity boundaries to timestamptz. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code, " \t\n\r\x0B"));
    }

    /** @return Attribute<string, string> */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizeCode($value));
    }

    /** @return HasMany<Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /** @return HasMany<PromotionRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'value' => 'integer',
            'minimum_cart_amount_minor' => 'integer',
            'maximum_discount_minor' => 'integer',
            'global_usage_limit' => 'integer',
            'per_customer_usage_limit' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
