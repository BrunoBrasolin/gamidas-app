<?php

namespace App\Models;

use App\Enums\KnowledgeAttachmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image, PDF or Office file stored on the private knowledge disk. Page attachments are
 * placed in the page text by id; a subject cover has no page. Attachments no
 * longer referenced by their page are marked detached and pruned later.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $page_id
 * @property KnowledgeAttachmentKind $kind
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $disk
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 * @property int $sort_order
 * @property int|null $reading_page Page (or slide) where the reader was left.
 * @property int|null $page_count Pages (or slides) the reader found.
 * @property CarbonImmutable|null $detached_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read KnowledgeSubject $subject
 * @property-read KnowledgePage|null $page
 */
#[Fillable(['subject_id', 'page_id', 'kind', 'filename', 'mime_type', 'size', 'disk', 'path', 'width', 'height', 'sort_order', 'reading_page', 'page_count', 'detached_at'])]
class KnowledgeAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => KnowledgeAttachmentKind::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'reading_page' => 'integer',
            'page_count' => 'integer',
            'detached_at' => 'datetime',
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
     * @return BelongsTo<KnowledgePage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(KnowledgePage::class, 'page_id');
    }
}
