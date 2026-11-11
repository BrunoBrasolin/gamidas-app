<?php

namespace Tests\Feature\Attention;

use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Services\Attention\AttentionReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class AttentionRankingTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private AttentionGame $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        $this->game = $this->household->attentionGames()->create(['title' => 'Sala', 'code' => 'RANK1']);
    }

    private function player(string $nickname, int $score, ?int $seconds): AttentionPlayer
    {
        $joined = CarbonImmutable::parse('2026-11-09 19:00:00');

        $player = new AttentionPlayer([
            'attention_game_id' => $this->game->id,
            'nickname' => $nickname,
            'token_hash' => hash('sha256', $nickname),
            'score' => $score,
            'finished_at' => $seconds === null ? null : $joined->addSeconds($seconds),
        ]);
        $player->forceFill(['created_at' => $joined, 'updated_at' => $joined])->saveQuietly();

        return $player;
    }

    /**
     * @return list<string>
     */
    private function order(): array
    {
        return array_column((new AttentionReport($this->game))->ranking(), 'nickname');
    }

    public function test_score_decides_first(): void
    {
        $this->player('Baixo', 1_000, 60);
        $this->player('Alto', 3_000, 400);
        $this->player('Meio', 2_000, 90);

        $this->assertSame(['Alto', 'Meio', 'Baixo'], $this->order());
    }

    public function test_on_equal_scores_whoever_finished_faster_wins(): void
    {
        $this->player('Lento', 2_000, 300);
        $this->player('Rapido', 2_000, 120);
        $this->player('Medio', 2_000, 200);

        $this->assertSame(['Rapido', 'Medio', 'Lento'], $this->order());
    }

    public function test_on_equal_scores_a_finished_player_beats_one_still_playing(): void
    {
        $this->player('Jogando', 2_000, null);
        $this->player('Terminou', 2_000, 500);

        $this->assertSame(['Terminou', 'Jogando'], $this->order());
    }

    public function test_a_full_tie_goes_to_whoever_joined_first(): void
    {
        $first = $this->player('Primeiro', 2_000, 100);
        $second = $this->player('Segundo', 2_000, 100);

        $this->assertLessThan($second->id, $first->id);
        $this->assertSame(['Primeiro', 'Segundo'], $this->order());
    }
}
