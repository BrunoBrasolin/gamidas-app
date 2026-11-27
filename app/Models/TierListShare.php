<?php

namespace App\Models;

use App\Enums\TierListKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The public link to one of a user's tier lists. Anyone with the token sees
 * the list; deleting the record takes the page down.
 *
 * @property int $id
 * @property int $user_id
 * @property TierListKind $kind
 * @property string $token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'token'])]
class TierListShare extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => TierListKind::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The address of the public page. */
    public function url(): string
    {
        return route('tier-lists.public', $this->token);
    }
}
