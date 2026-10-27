<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a household and its Jellyfin server: the hash of the
 * token the webhook sends and when it last delivered an event.
 *
 * @property int $id
 * @property int $household_id
 * @property string $token_hash
 * @property CarbonImmutable|null $last_received_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'token_hash', 'last_received_at'])]
#[Hidden(['token_hash'])]
class JellyfinConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_received_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
