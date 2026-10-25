<?php

namespace App\Models;

use App\Enums\KnowledgeColor;
use Carbon\CarbonImmutable;
use Database\Factories\KnowledgeSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A book of the knowledge base: a subject with optional categories, pages and
 * attachments. Subjects are private to the user who created them.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property KnowledgeColor $accent_color
 * @property int|null $cover_attachment_id
 * @property bool $is_pinned
 * @property int $sort_order
 * @property CarbonImmutable|null $last_opened_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read KnowledgeAttachment|null $cover
 */
#[Fillable(['user_id', 'title', 'description', 'accent_color', 'cover_attachment_id', 'is_pinned', 'sort_order', 'last_opened_at', 'archived_at'])]
class KnowledgeSubject extends Model
{
    /** @use HasFactory<KnowledgeSubjectFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accent_color' => KnowledgeColor::class,
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
     * @return HasMany<KnowledgeCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(KnowledgeCategory::class, 'subject_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<KnowledgePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(KnowledgePage::class, 'subject_id');
    }

    /**
     * @return HasMany<KnowledgeAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeAttachment::class, 'subject_id');
    }

    /**
     * @return BelongsTo<KnowledgeAttachment, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(KnowledgeAttachment::class, 'cover_attachment_id');
    }
}
