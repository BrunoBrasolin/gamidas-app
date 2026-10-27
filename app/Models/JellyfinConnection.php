<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a user and their Jellyfin server: the hash of the token
 * the webhook sends and when it last delivered an event.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property CarbonImmutable|null $last_received_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'last_received_at'])]
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
