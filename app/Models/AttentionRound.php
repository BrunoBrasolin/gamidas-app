<?php

namespace App\Models;

use App\Enums\AttentionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's result in one challenge: how well they did (hits, errors,
 * misses, times) and which kind of attention they said the challenge used.
 *
 * @property int $id
 * @property int $attention_player_id
 * @property AttentionType $type
 * @property bool $correct
 * @property int $accuracy Percentage, 0–100.
 * @property int $hits
 * @property int $errors
 * @property int $misses
 * @property int $duration_ms
 * @property int|null $reaction_ms
 * @property AttentionType|null $guess
 * @property bool $guess_correct
 * @property int $performance_points
 * @property int $points
 * @property array<string, int>|null $details
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'attention_player_id', 'type', 'correct', 'accuracy', 'hits', 'errors', 'misses', 'duration_ms', 'reaction_ms',
    'guess', 'guess_correct', 'performance_points', 'points', 'details',
])]
class AttentionRound extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttentionType::class,
            'guess' => AttentionType::class,
            'correct' => 'boolean',
            'guess_correct' => 'boolean',
            'accuracy' => 'integer',
            'hits' => 'integer',
            'errors' => 'integer',
            'misses' => 'integer',
            'duration_ms' => 'integer',
            'reaction_ms' => 'integer',
            'performance_points' => 'integer',
            'points' => 'integer',
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AttentionPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(AttentionPlayer::class, 'attention_player_id');
    }
}
