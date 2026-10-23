<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Markdown note of a user for a local calendar date (one per day). The
 * content is stored as written and rendered safely on the client, without
 * raw HTML.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $note_date
 * @property string $content
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'note_date', 'content', 'revision'])]
class DailyNote extends Model
{
    /**
     * Largest note accepted, in characters.
     */
    public const MAX_LENGTH = 200_000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'note_date' => DateOnly::class,
            'revision' => 'integer',
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
     * A plain-text excerpt for lists and previews.
     */
    public function preview(int $length = 140): string
    {
        $text = preg_replace(['/\[[ xX]?\]|[#>*_`~|]+/', '/\s+/'], ' ', $this->content) ?? $this->content;

        return mb_strimwidth(trim($text), 0, $length, '…');
    }
}
