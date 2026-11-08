<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An activity counted as complementary hours: the hours it is worth, its
 * attachments (certificates, the filled-in report and other files on the
 * private disk) and whether the report was already filled in. Private to its
 * owner.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property CarbonImmutable|null $completed_on
 * @property int $minutes
 * @property bool $report_done
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ComplementaryActivityAttachment> $attachments
 */
#[Fillable(['user_id', 'title', 'completed_on', 'minutes', 'report_done'])]
class ComplementaryActivity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_on' => DateOnly::class,
            'minutes' => 'integer',
            'report_done' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ComplementaryActivityAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ComplementaryActivityAttachment::class);
    }
}
