<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room of the attention game, shared by every member of the household.
 * Anyone with the code plays it on the public page while it is open.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property string $code
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'created_by_user_id', 'title', 'code', 'closed_at'])]
class AttentionGame extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    /**
     * Codes are typed by hand on the phone, so lowercase finds them too.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field === 'code') {
            $value = strtoupper((string) $value);
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<AttentionPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(AttentionPlayer::class)->orderBy('id');
    }
}
