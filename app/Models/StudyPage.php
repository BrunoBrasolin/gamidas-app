<?php

namespace App\Models;

use App\Enums\StudyPageStatus;
use App\Support\StudyContent;
use Carbon\CarbonImmutable;
use Database\Factories\StudyPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A study page: the editor document (Tiptap/ProseMirror JSON, sanitized by
 * {@see StudyContent}) and its plain text, used for search.
 * The revision grows on every content save, so stale autosaves are refused.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $chapter_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $content_text
 * @property int $revision
 * @property int $sort_order
 * @property StudyPageStatus $status
 * @property int $reading_position Scroll position in the reader, per mille.
 * @property CarbonImmutable|null $last_read_at
 * @property CarbonImmutable|null $bookmarked_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StudySubject $subject
 * @property-read StudyChapter|null $chapter
 */
#[Fillable(['subject_id', 'chapter_id', 'title', 'content', 'content_text', 'revision', 'sort_order', 'status', 'reading_position', 'last_read_at', 'bookmarked_at', 'archived_at'])]
class StudyPage extends Model
{
    /** @use HasFactory<StudyPageFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'unread', 'revision' => 0, 'reading_position' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'revision' => 'integer',
            'sort_order' => 'integer',
            'status' => StudyPageStatus::class,
            'reading_position' => 'integer',
            'last_read_at' => 'datetime',
            'bookmarked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StudySubject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(StudySubject::class, 'subject_id');
    }

    /**
     * @return BelongsTo<StudyChapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(StudyChapter::class, 'chapter_id');
    }

    /**
     * @return HasMany<StudyAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(StudyAttachment::class, 'page_id')->orderBy('sort_order')->orderBy('id');
    }
}
