<?php

namespace App\Models;

use App\Enums\ComplementaryAttachmentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a complementary activity (certificate, filled-in report
 * or anything else), kept on the private disk under a random name.
 *
 * @property int $id
 * @property int $complementary_activity_id
 * @property ComplementaryAttachmentKind $kind
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'disk', 'path', 'filename', 'mime', 'size'])]
class ComplementaryActivityAttachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ComplementaryAttachmentKind::class,
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ComplementaryActivity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(ComplementaryActivity::class, 'complementary_activity_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
