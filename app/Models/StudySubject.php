<?php

namespace App\Models;

use App\Enums\StudyColor;
use Carbon\CarbonImmutable;
use Database\Factories\StudySubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A book of the study library: a subject with optional chapters, pages and
 * attachments. Subjects are private to the user who created them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property StudyColor $accent_color
 * @property int|null $cover_attachment_id
 * @property bool $is_pinned
 * @property int $sort_order
 * @property CarbonImmutable|null $last_opened_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StudyAttachment|null $cover
 */
#[Fillable(['user_id', 'title', 'description', 'accent_color', 'cover_attachment_id', 'is_pinned', 'sort_order', 'last_opened_at', 'archived_at'])]
class StudySubject extends Model
{
    /** @use HasFactory<StudySubjectFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accent_color' => StudyColor::class,
            'is_pinned' => 'boolean',
            'sort_order' => 'integer',
            'last_opened_at' => 'datetime',
            'archived_at' => 'datetime',
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
     * @return HasMany<StudyChapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(StudyChapter::class, 'subject_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<StudyPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(StudyPage::class, 'subject_id');
    }

    /**
     * @return HasMany<StudyAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(StudyAttachment::class, 'subject_id');
    }

    /**
     * @return BelongsTo<StudyAttachment, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(StudyAttachment::class, 'cover_attachment_id');
    }
}
