<?php

namespace App\Services\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Models\AttentionRound;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rooms of the attention game and the play itself: joining under a nickname
 * and recording each challenge once, keeping the player's totals in sync.
 *
 * @phpstan-type RoundData array{type: AttentionType, correct: bool, accuracy: int, hits: int, errors: int, misses: int, duration_ms: int, reaction_ms: int|null, guess: AttentionType|null, details: array<string, int>|null}
 */
class AttentionGameService
{
    /** Letters and digits that can't be mistaken for one another. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 5;

    /** The page is public, so a room takes a bounded number of players. */
    public const MAX_PLAYERS = 300;

    public function create(Household $household, User $creator, string $title): AttentionGame
    {
        return $household->attentionGames()->create([
            'created_by_user_id' => $creator->id,
            'title' => $title,
            'code' => $this->uniqueCode(),
        ]);
    }

    public function close(AttentionGame $game): void
    {
        $game->update(['closed_at' => $game->closed_at ?? now()]);
    }

    public function reopen(AttentionGame $game): void
    {
        $game->update(['closed_at' => null]);
    }

    public function delete(AttentionGame $game): void
    {
        $game->delete();
    }

    /**
     * Add a player to an open room. Nicknames are unique per room, ignoring
     * case. Returns the player and the plain token the phone keeps.
     *
     * @return array{AttentionPlayer, string}
     */
    public function join(AttentionGame $game, string $nickname): array
    {
        $this->ensureOpen($game);

        if ($game->players()->count() >= self::MAX_PLAYERS) {
            throw ValidationException::withMessages(['nickname' => 'Esta sala já está cheia.']);
        }

        $taken = $game->players()->get(['nickname'])
            ->contains(fn (AttentionPlayer $player) => Str::lower($player->nickname) === Str::lower($nickname));

        if ($taken) {
            throw $this->nicknameTaken();
        }

        $token = Str::random(40);

        try {
            $player = $game->players()->create([
                'nickname' => $nickname,
                'token_hash' => AttentionPlayer::hashToken($token),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nicknameTaken();
        }

        return [$player, $token];
    }

    public function findPlayer(AttentionGame $game, string $token): ?AttentionPlayer
    {
        return $game->players()->where('token_hash', AttentionPlayer::hashToken($token))->first();
    }

    /**
     * Record a challenge the player answered and add its points. Each
     * challenge counts once; the player finishes with the last one.
     *
     * @param  RoundData  $data
     */
    public function recordRound(AttentionPlayer $player, array $data): AttentionRound
    {
        $this->ensureOpen($player->game);

        try {
            return DB::transaction(function () use ($player, $data) {
                /** @var AttentionPlayer $locked */
                $locked = AttentionPlayer::query()->lockForUpdate()->findOrFail($player->id);

                if ($locked->rounds()->where('type', $data['type'])->exists()) {
                    throw $this->alreadyAnswered();
                }

                $performance = AttentionScore::performance($data['type'], $data['accuracy'], $data['correct'], $data['duration_ms'], $data['reaction_ms']);
                $guessCorrect = $data['guess'] === $data['type'];

                $round = $locked->rounds()->create([
                    ...$data,
                    'guess_correct' => $guessCorrect,
                    'performance_points' => $performance,
                    'points' => $performance + AttentionScore::concept($guessCorrect),
                ]);

                $finished = $locked->rounds()->count() === count(AttentionType::cases());

                $locked->update([
                    'score' => $locked->score + $round->points,
                    'correct_rounds' => $locked->correct_rounds + ($round->correct ? 1 : 0),
                    'finished_at' => $finished ? now() : null,
                ]);

                $player->setRawAttributes($locked->getAttributes(), true);

                return $round;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyAnswered();
        }
    }

    private function ensureOpen(AttentionGame $game): void
    {
        if (! $game->isOpen()) {
            throw ValidationException::withMessages(['game' => 'Esta sala foi encerrada.']);
        }
    }

    private function nicknameTaken(): ValidationException
    {
        return ValidationException::withMessages(['nickname' => 'Esse apelido já está em uso nesta sala. Escolha outro.']);
    }

    private function alreadyAnswered(): ValidationException
    {
        return ValidationException::withMessages(['type' => 'Você já respondeu este desafio.']);
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (AttentionGame::query()->where('code', $code)->exists());

        return $code;
    }
}
