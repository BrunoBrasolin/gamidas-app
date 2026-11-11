<?php

namespace Tests\Feature\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Services\Attention\AttentionGameService;
use App\Support\AttentionFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class AttentionPdfTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    private function createGame(string $title = 'Turma B'): AttentionGame
    {
        return app(AttentionGameService::class)->create($this->household, $this->leticia, $title);
    }

    private function play(AttentionGame $game, string $nickname, int $rounds, int $points = 600): AttentionPlayer
    {
        $player = $game->players()->create(['nickname' => $nickname, 'token_hash' => hash('sha256', $nickname)]);

        foreach (array_slice(AttentionType::cases(), 0, $rounds) as $type) {
            $player->rounds()->create([
                'type' => $type,
                'correct' => true,
                'accuracy' => 90,
                'hits' => 4,
                'errors' => 1,
                'misses' => 0,
                'duration_ms' => 4_250,
                'reaction_ms' => $type === AttentionType::Alternating ? 420 : null,
                'guess' => $type,
                'guess_correct' => true,
                'performance_points' => $points - 200,
                'points' => $points,
                'details' => ['expected' => 5, 'answer' => 5, 'perseverations' => 1],
            ]);
        }

        $player->update([
            'score' => $rounds * $points,
            'correct_rounds' => $rounds,
            'finished_at' => $rounds === count(AttentionType::cases()) ? now()->addSeconds(95) : null,
        ]);

        return $player;
    }

    public function test_the_report_exports_as_a_pdf(): void
    {
        $game = $this->createGame('Turma B');
        $this->play($game, 'Ana', 5);
        $this->play($game, 'Beto', 2);

        $response = $this->actingAs($this->leticia)->get(route('attention.pdf', $game))->assertOk();
        $pdf = $response->streamedContent();

        $this->assertStringContainsString('jogo-da-atencao-turma-b-'.$game->code.'.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    public function test_an_empty_room_exports_too(): void
    {
        $game = $this->createGame();

        $this->assertStringStartsWith('%PDF-', $this->actingAs($this->leticia)->get(route('attention.pdf', $game))->assertOk()->streamedContent());
    }

    public function test_only_the_household_can_export_a_room(): void
    {
        $game = $this->createGame();

        $this->actingAs($this->createOutsider())->get(route('attention.pdf', $game))->assertNotFound();
        auth()->logout();
        $this->get(route('attention.pdf', $game))->assertRedirect();
    }

    public function test_numbers_are_worded_as_on_the_report_page(): void
    {
        $this->assertSame('1.234', AttentionFormat::points(1234));
        $this->assertSame('—', AttentionFormat::points(null));
        $this->assertSame('87%', AttentionFormat::rate(87));
        $this->assertSame('4,3 s', AttentionFormat::seconds(4_250));
        $this->assertSame('2 min 05 s', AttentionFormat::elapsed(125));
        $this->assertSame('45 s', AttentionFormat::elapsed(45));
        $this->assertSame('1,5', AttentionFormat::average(1.5));

        $this->assertSame('3/5 Q encontrados, 2 toques errados', AttentionFormat::roundDetail(
            ['type' => 'selective', 'hits' => 3, 'errors' => 2, 'misses' => 0, 'details' => ['expected' => 5]],
        ));
        $this->assertSame('Contou — (eram —)', AttentionFormat::roundDetail(
            ['type' => 'sustained', 'hits' => 0, 'errors' => 0, 'misses' => 0, 'details' => null],
        ));
        $this->assertSame('Círculos 4/4 · setes 2/3', AttentionFormat::roundDetail(
            ['type' => 'divided', 'hits' => 0, 'errors' => 0, 'misses' => 0, 'details' => ['expected' => 4, 'answer' => 4, 'expected_secondary' => 3, 'answer_secondary' => 2]],
        ));
    }
}
