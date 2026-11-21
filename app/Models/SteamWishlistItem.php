<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game on a Steam account's wishlist, with what the store showed at the
 * last sync: price (integer cents in the store's currency), discount,
 * release and reviews.
 *
 * @property int $id
 * @property int $steam_account_id
 * @property int $app_id
 * @property string $name
 * @property int $priority The user's rank on Steam; 0 when unranked.
 * @property CarbonImmutable|null $added_at
 * @property int|null $price_cents
 * @property int|null $original_price_cents Before the discount.
 * @property int $discount_percent
 * @property string|null $price_formatted As the store writes it, e.g. "R$33,99".
 * @property string|null $original_price_formatted
 * @property bool $is_free
 * @property CarbonImmutable|null $release_at
 * @property bool $coming_soon
 * @property string|null $review_label E.g. "Muito positivas".
 * @property int|null $review_percent
 * @property int|null $review_count
 * @property string|null $header_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'steam_account_id', 'app_id', 'name', 'priority', 'added_at', 'price_cents', 'original_price_cents',
    'discount_percent', 'price_formatted', 'original_price_formatted', 'is_free', 'release_at', 'coming_soon',
    'review_label', 'review_percent', 'review_count', 'header_url',
])]
class SteamWishlistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'app_id' => 'integer',
            'priority' => 'integer',
            'added_at' => 'datetime',
            'price_cents' => 'integer',
            'original_price_cents' => 'integer',
            'discount_percent' => 'integer',
            'is_free' => 'boolean',
            'release_at' => 'datetime',
            'coming_soon' => 'boolean',
            'review_percent' => 'integer',
            'review_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SteamAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SteamAccount::class, 'steam_account_id');
    }
}
