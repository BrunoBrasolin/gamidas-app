<?php

namespace App\Models;

use App\Enums\GameTier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A game placed in the user's tier list.
 *
 * @property int $id
 * @property int $user_id
 * @property int $app_id
 * @property string $name The game's name when last placed.
 * @property GameTier $tier
 * @property int $position Order within the tier, from 0.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'app_id', 'name', 'tier', 'position'])]
class SteamTierEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['app_id' => 'integer', 'tier' => GameTier::class, 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
