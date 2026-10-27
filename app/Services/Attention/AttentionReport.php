<?php

namespace App\Services\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Models\AttentionRound;
use Illuminate\Support\Collection;

/**
 * Indicators of a room: overall numbers, how each kind of attention went
 * (performance and whether players recognized it) and the ranking with
 * every player's rounds. Rates are whole percentages, null without data.
 */
class AttentionReport
{
    /** @var Collection<int, AttentionPlayer> */
    private Collection $players;

    /** @var Collection<int, AttentionRound> */
    private Collection $rounds;

    public function __construct(AttentionGame $game)
    {
        $this->players = $this->rank($game->players()->with('rounds')->get());
        $this->rounds = $this->players->flatMap(fn (AttentionPlayer $player) => $player->rounds)->values();
    }

    /**
     * @return array<string, int|float|null>
     */
    public function summary(): array
    {
        $finished = $this->players->filter(fn (AttentionPlayer $player) => $player->finished_at !== null);
        $reactions = $this->rounds->pluck('reaction_ms')->filter(fn (?int $ms) => $ms !== null);

        return [
            'players' => $this->players->count(),
            'finished' => $finished->count(),
            'playing' => $this->players->count() - $finished->count(),
            'average_score' => $this->average($finished->pluck('score')),
            'best_score' => $this->players->max('score'),
            'max_score' => AttentionScore::gameMax(),
            'success_rate' => $this->rate($this->rounds, fn (AttentionRound $round) => $round->correct),
            'concept_rate' => $this->rate($this->rounds, fn (AttentionRound $round) => $round->guess_correct),
            'average_accuracy' => $this->average($this->rounds->pluck('accuracy')),
            'average_elapsed_seconds' => $this->average($finished->map(fn (AttentionPlayer $player) => $player->elapsedSeconds())),
            'average_reaction_ms' => $this->average($reactions),
        ];
    }

    /**
     * One row per kind of attention, in the order the challenges are played.
     * Guesses count what players answered for that challenge ("none" when
     * the time ran out), showing which kinds get confused.
     *
     * @return list<array<string, mixed>>
     */
    public function types(): array
    {
        return array_map(function (AttentionType $type) {
            $rounds = $this->rounds->filter(fn (AttentionRound $round) => $round->type === $type)->values();
            $guesses = ['none' => 0] + array_fill_keys(array_map(fn (AttentionType $case) => $case->value, AttentionType::cases()), 0);

            foreach ($rounds as $round) {
                $guesses[$round->guess->value ?? 'none']++;
            }

            return [
                'type' => $type->value,
                'label' => $type->label(),
                'challenge' => $type->challenge(),
                'rounds' => $rounds->count(),
                'success_rate' => $this->rate($rounds, fn (AttentionRound $round) => $round->correct),
                'concept_rate' => $this->rate($rounds, fn (AttentionRound $round) => $round->guess_correct),
                'average_accuracy' => $this->average($rounds->pluck('accuracy')),
                'average_duration_ms' => $this->average($rounds->pluck('duration_ms')),
                'average_reaction_ms' => $this->average($rounds->pluck('reaction_ms')->filter(fn (?int $ms) => $ms !== null)),
                'average_errors' => $this->average($rounds->pluck('errors'), 1),
                'average_misses' => $this->average($rounds->pluck('misses'), 1),
                'average_points' => $this->average($rounds->pluck('points')),
                'guesses' => $guesses,
            ];
        }, AttentionType::cases());
    }

    /**
     * Every player, best first.
     *
     * @return list<array<string, mixed>>
     */
    public function players(): array
    {
        return $this->players->values()->map(fn (AttentionPlayer $player, int $index) => [
            'id' => $player->id,
            'position' => $index + 1,
            'nickname' => $player->nickname,
            'score' => $player->score,
            'correct_rounds' => $player->correct_rounds,
            'concept_hits' => $player->rounds->where('guess_correct', true)->count(),
            'answered' => $player->rounds->count(),
            'errors' => (int) $player->rounds->sum('errors'),
            'misses' => (int) $player->rounds->sum('misses'),
            'duration_ms' => (int) $player->rounds->sum('duration_ms'),
            'elapsed_seconds' => $player->elapsedSeconds(),
            'finished' => $player->finished_at !== null,
            'joined_at' => $player->created_at?->toIso8601String(),
            'rounds' => $player->rounds->map(fn (AttentionRound $round) => [
                'type' => $round->type->value,
                'correct' => $round->correct,
                'accuracy' => $round->accuracy,
                'hits' => $round->hits,
                'errors' => $round->errors,
                'misses' => $round->misses,
                'duration_ms' => $round->duration_ms,
                'reaction_ms' => $round->reaction_ms,
                'guess' => $round->guess?->value,
                'guess_correct' => $round->guess_correct,
                'points' => $round->points,
                'details' => $round->details,
            ])->values()->all(),
        ])->all();
    }

    /**
     * The short ranking shown to players and on the projector.
     *
     * @return list<array{id: int, position: int, nickname: string, score: int, correct_rounds: int, answered: int, finished: bool}>
     */
    public function ranking(?int $limit = null): array
    {
        $players = $limit === null ? $this->players : $this->players->take($limit);

        return $players->values()->map(fn (AttentionPlayer $player, int $index) => [
            'id' => $player->id,
            'position' => $index + 1,
            'nickname' => $player->nickname,
            'score' => $player->score,
            'correct_rounds' => $player->correct_rounds,
            'answered' => $player->rounds->count(),
            'finished' => $player->finished_at !== null,
        ])->all();
    }

    /**
     * Highest score first; on a tie, whoever finished, and faster, wins.
     *
     * @param  Collection<int, AttentionPlayer>  $players
     * @return Collection<int, AttentionPlayer>
     */
    private function rank(Collection $players): Collection
    {
        return $players->sort(function (AttentionPlayer $a, AttentionPlayer $b) {
            return [$b->score, $b->finished_at !== null, -($a->elapsedSeconds() ?? PHP_INT_MAX), -$a->id]
                <=> [$a->score, $a->finished_at !== null, -($b->elapsedSeconds() ?? PHP_INT_MAX), -$b->id];
        })->values();
    }

    /**
     * @param  Collection<int, AttentionRound>  $rounds
     * @param  callable(AttentionRound): bool  $passes
     */
    private function rate(Collection $rounds, callable $passes): ?int
    {
        return $rounds->isEmpty() ? null : (int) round($rounds->filter($passes)->count() * 100 / $rounds->count());
    }

    /**
     * @param  Collection<int, int|null>  $values
     */
    private function average(Collection $values, int $precision = 0): int|float|null
    {
        $values = $values->filter(fn ($value) => $value !== null);

        if ($values->isEmpty()) {
            return null;
        }

        $average = round($values->avg(), $precision);

        return $precision === 0 ? (int) $average : $average;
    }
}
