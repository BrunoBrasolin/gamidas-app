<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An activity counted as complementary hours: its certificate (a PDF or an
 * image on the private disk), the hours it is worth and an optional Markdown
 * report. Private to its owner.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property CarbonImmutable|null $completed_on
 * @property int $minutes
 * @property string $certificate_disk
 * @property string $certificate_path
 * @property string $certificate_filename
 * @property string $certificate_mime
 * @property int $certificate_size
 * @property string|null $report
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'title', 'completed_on', 'minutes', 'certificate_disk', 'certificate_path', 'certificate_filename', 'certificate_mime', 'certificate_size', 'report'])]
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
            'certificate_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasReport(): bool
    {
        return $this->report !== null && trim($this->report) !== '';
    }

    public function certificateIsImage(): bool
    {
        return str_starts_with($this->certificate_mime, 'image/');
    }
}
