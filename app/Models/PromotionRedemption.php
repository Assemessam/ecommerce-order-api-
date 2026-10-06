<?php

namespace App\Models;

use Database\Factories\PromotionRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['promotion_id', 'user_id', 'redemption_key', 'discount_minor', 'redeemed_at'])]
class PromotionRedemption extends Model
{
    /** @use HasFactory<PromotionRedemptionFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discount_minor' => 'integer', 'redeemed_at' => 'immutable_datetime'];
    }
}
