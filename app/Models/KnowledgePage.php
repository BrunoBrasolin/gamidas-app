<?php

namespace App\Models;

use App\Enums\KnowledgePageStatus;
use App\Support\KnowledgeContent;
use Carbon\CarbonImmutable;
use Database\Factories\KnowledgePageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A knowledge page: the editor document (Tiptap/ProseMirror JSON, sanitized by
 * {@see KnowledgeContent}) and its plain text, used for search.
 * The revision grows on every content save, so stale autosaves are refused.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $category_id
 * @property string $title
 * @property array<string, mixed>|null $content
 * @property string|null $content_text
 * @property int $revision
 * @property int $sort_order
 * @property KnowledgePageStatus $status
 * @property int $reading_position Scroll position in the reader, per mille.
 * @property CarbonImmutable|null $last_read_at
 * @property CarbonImmutable|null $bookmarked_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read KnowledgeSubject $subject
 * @property-read KnowledgeCategory|null $category
 */
#[Fillable(['subject_id', 'category_id', 'title', 'content', 'content_text', 'revision', 'sort_order', 'status', 'reading_position', 'last_read_at', 'bookmarked_at', 'archived_at'])]
class KnowledgePage extends Model
{
    /** @use HasFactory<KnowledgePageFactory> */
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
            'status' => KnowledgePageStatus::class,
            'reading_position' => 'integer',
            'last_read_at' => 'datetime',
            'bookmarked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeSubject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSubject::class, 'subject_id');
    }

    /**
     * @return BelongsTo<KnowledgeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    /**
     * @return HasMany<KnowledgeAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeAttachment::class, 'page_id')->orderBy('sort_order')->orderBy('id');
    }
}
