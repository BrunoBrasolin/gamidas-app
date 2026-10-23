<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TimeCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job or project whose hours the user tracks day by day (for example one
 * per client). Archiving hides it from new entries but keeps its history.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'name', 'archived_at'])]
class TimeCategory extends Model
{
    /** @use HasFactory<TimeCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TimeLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
