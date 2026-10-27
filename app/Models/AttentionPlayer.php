<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone playing a room of the attention game under a nickname. The score
 * and the count of correct rounds are kept in sync with the rounds; the
 * player has finished once every challenge was answered.
 *
 * @property int $id
 * @property int $attention_game_id
 * @property string $nickname
 * @property string $token_hash
 * @property int $score
 * @property int $correct_rounds
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['attention_game_id', 'nickname', 'token_hash', 'score', 'correct_rounds', 'finished_at'])]
#[Hidden(['token_hash'])]
class AttentionPlayer extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['score' => 0, 'correct_rounds' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'integer', 'correct_rounds' => 'integer', 'finished_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Seconds between joining and answering the last challenge.
     */
    public function elapsedSeconds(): ?int
    {
        return $this->finished_at === null || $this->created_at === null
            ? null
            : (int) $this->created_at->diffInSeconds($this->finished_at);
    }

    /**
     * @return BelongsTo<AttentionGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(AttentionGame::class, 'attention_game_id');
    }

    /**
     * @return HasMany<AttentionRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(AttentionRound::class)->orderBy('id');
    }
}
