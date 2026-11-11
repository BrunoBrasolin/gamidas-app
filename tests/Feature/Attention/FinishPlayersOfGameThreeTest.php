<?php

namespace Tests\Feature\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Services\Attention\AttentionReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

/**
 * The data migration that finishes the players room 3 left "playing" after a
 * database outage.
 */
class FinishPlayersOfGameThreeTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private const MIGRATION = '2026_11_10_000001_finish_players_of_attention_game_3.php';

    private CarbonImmutable $joined;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        $this->joined = CarbonImmutable::parse('2026-11-09 19:00:00');
    }

    private function room(int $id): AttentionGame
    {
        $game = new AttentionGame([
            'household_id' => $this->household->id,
            'title' => 'Sala '.$id,
            'code' => 'ROOM'.$id,
        ]);
        $game->forceFill(['id' => $id])->save();

        return $game;
    }

    /**
     * A player who joined at 19:00 and whose last answer was $seconds later.
     * Only a player with every challenge answered is finished.
     */
    private function player(AttentionGame $game, string $nickname, int $score, int $rounds, int $seconds, bool $finished = false): AttentionPlayer
    {
        $player = new AttentionPlayer([
            'attention_game_id' => $game->id,
            'nickname' => $nickname,
            'token_hash' => hash('sha256', $nickname.$game->id),
            'score' => $score,
            'correct_rounds' => $rounds,
            'finished_at' => $finished ? $this->joined->addSeconds($seconds) : null,
        ]);
        $player->forceFill([
            'created_at' => $this->joined,
            'updated_at' => $this->joined->addSeconds($seconds),
        ])->saveQuietly();

        foreach (array_slice(AttentionType::cases(), 0, $rounds) as $type) {
            $player->rounds()->create([
                'type' => $type, 'correct' => true, 'accuracy' => 100, 'hits' => 1, 'errors' => 0, 'misses' => 0,
                'duration_ms' => 1_000, 'reaction_ms' => null, 'guess' => $type, 'guess_correct' => true,
                'performance_points' => 0, 'points' => 0,
            ]);
        }

        return $player;
    }

    private function migrate(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    private function leaderOf(AttentionGame $game): ?int
    {
        return (new AttentionReport($game))->ranking(1)[0]['id'] ?? null;
    }

    public function test_players_left_playing_in_room_three_are_finished_at_their_last_answer(): void
    {
        $this->room(1);
        $this->room(2);
        $three = $this->room(3);
        $other = AttentionGame::query()->findOrFail(2);

        $stranded = $this->player($three, 'Ana', 3_100, 4, 140);
        $slow = $this->player($three, 'Beto', 1_200, 2, 300);
        $silent = $this->player($three, 'Caio', 0, 0, 10);
        $done = $this->player($three, 'Dani', 4_000, 5, 200, finished: true);
        $elsewhere = $this->player($other, 'Eva', 900, 3, 120);

        $this->migrate();

        $stranded->refresh();
        $this->assertEquals($this->joined->addSeconds(140), $stranded->finished_at);
        $this->assertSame(140, $stranded->elapsedSeconds());
        $this->assertSame(3_100, $stranded->score);
        $this->assertSame(4, $stranded->correct_rounds);
        $this->assertEquals($this->joined->addSeconds(300), $slow->refresh()->finished_at);

        $this->assertNull($silent->refresh()->finished_at, 'Nobody who answered nothing finished.');
        $this->assertEquals($this->joined->addSeconds(200), $done->refresh()->finished_at);
        $this->assertNull($elsewhere->refresh()->finished_at, 'Other rooms are not touched.');
    }

    public function test_the_top_player_stays_on_top(): void
    {
        $three = $this->room(3);
        $leader = $this->player($three, 'Ana', 4_500, 4, 150);
        $this->player($three, 'Beto', 4_100, 5, 100, finished: true);
        $this->player($three, 'Caio', 2_000, 3, 60);

        $this->assertSame($leader->id, $this->leaderOf($three));

        $this->migrate();

        $this->assertSame($leader->id, $this->leaderOf($three));
        $this->assertNotNull($leader->refresh()->finished_at);
        $this->assertSame(1, (new AttentionReport($three))->players()[0]['position']);
    }

    public function test_a_tie_for_first_never_moves_the_leader(): void
    {
        $three = $this->room(3);
        // The leader is already finished; two others share their score and
        // would finish faster and slower than them, so whichever way ties are
        // broken, one of them would overtake the leader if just finished.
        $leader = $this->player($three, 'Ana', 4_000, 5, 150, finished: true);
        $faster = $this->player($three, 'Beto', 4_000, 4, 90);
        $slower = $this->player($three, 'Caio', 4_000, 4, 400);

        $this->assertSame($leader->id, $this->leaderOf($three));

        $this->migrate();

        $this->assertSame($leader->id, $this->leaderOf($three));
        $this->assertTrue(
            $faster->refresh()->finished_at === null || $slower->refresh()->finished_at === null,
            'Whoever would overtake the leader is put back to playing.',
        );
    }

    public function test_it_does_nothing_without_room_three_and_can_run_twice(): void
    {
        $this->migrate();

        $three = $this->room(3);
        $player = $this->player($three, 'Ana', 1_000, 3, 80);

        $this->migrate();
        $first = $player->refresh()->finished_at;
        $this->migrate();

        $this->assertNotNull($first);
        $this->assertEquals($first, $player->refresh()->finished_at);
    }
}
