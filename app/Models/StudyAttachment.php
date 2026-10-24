<?php

namespace App\Models;

use App\Enums\StudyAttachmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image or PDF stored on the private study disk. Page attachments are
 * placed in the page text by id; a subject cover has no page. Attachments no
 * longer referenced by their page are marked detached and pruned later.
 *
 * @property int $id
 * @property int $subject_id
 * @property int|null $page_id
 * @property StudyAttachmentKind $kind
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $disk
 * @property string $path
 * @property int|null $width
 * @property int|null $height
 * @property int $sort_order
 * @property CarbonImmutable|null $detached_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StudySubject $subject
 * @property-read StudyPage|null $page
 */
#[Fillable(['subject_id', 'page_id', 'kind', 'filename', 'mime_type', 'size', 'disk', 'path', 'width', 'height', 'sort_order', 'detached_at'])]
class StudyAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StudyAttachmentKind::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'sort_order' => 'integer',
            'detached_at' => 'datetime',
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
     * @return BelongsTo<StudyPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(StudyPage::class, 'page_id');
    }
}
