<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An optional group of pages inside a subject. Categories nest: a category
 * may sit inside another one (parent_id) of the same subject, down to
 * {@see self::MAX_DEPTH} levels. sort_order orders siblings.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $parent_id
 * @property string $title
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read KnowledgeSubject $subject
 * @property-read KnowledgeCategory|null $parent
 */
#[Fillable(['subject_id', 'parent_id', 'title', 'sort_order'])]
class KnowledgeCategory extends Model
{
    /** Levels of nesting, counting the top-level categories as 1. */
    public const MAX_DEPTH = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'sort_order' => 'integer'];
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
    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'parent_id');
    }

    /**
     * @return HasMany<KnowledgeCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(KnowledgeCategory::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<KnowledgePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(KnowledgePage::class, 'category_id');
    }
}
