<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An optional section of a subject grouping some of its pages.
 *
 * @property int $id
 * @property int $subject_id
 * @property string $title
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StudySubject $subject
 */
#[Fillable(['subject_id', 'title', 'sort_order'])]
class StudyChapter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<StudySubject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(StudySubject::class, 'subject_id');
    }

    /**
     * @return HasMany<StudyPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(StudyPage::class, 'chapter_id');
    }
}
