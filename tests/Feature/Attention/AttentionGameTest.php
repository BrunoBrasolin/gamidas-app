<?php

namespace Tests\Feature\Attention;

use App\Enums\AttentionType;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Services\Attention\AttentionGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class AttentionGameTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    private function createGame(string $title = 'Apresentação de Atenção'): AttentionGame
    {
        return app(AttentionGameService::class)->create($this->household, $this->leticia, $title);
    }

    /**
     * @return array<string, mixed>
     */
    private function round(AttentionType $type, array $overrides = []): array
    {
        return [
            'type' => $type->value,
            'guess' => $type->value,
            'correct' => true,
            'accuracy' => 100,
            'hits' => 5,
            'errors' => 0,
            'misses' => 0,
            'duration_ms' => 0,
            'reaction_ms' => $type === AttentionType::Alternating ? 0 : null,
            ...$overrides,
        ];
    }

    public function test_rooms_are_created_for_the_household_with_a_code(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('attention.store'), ['title' => ' Turma B '])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('attention.show', AttentionGame::query()->sole()));

        $game = AttentionGame::query()->sole();
        $this->assertSame($this->household->id, $game->household_id);
        $this->assertSame($this->leticia->id, $game->created_by_user_id);
        $this->assertSame('Turma B', $game->title);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}$/', $game->code);
        $this->assertTrue($game->isOpen());
    }

    public function test_every_member_of_the_household_sees_the_rooms(): void
    {
        $game = $this->createGame();

        $this->actingAs($this->bruno)
            ->get(route('attention.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('attention/index', false)
                ->has('games', 1)
                ->where('games.0.code', $game->code)
                ->where('games.0.created_by', 'Letícia')
                ->where('games.0.players', 0));

        $this->actingAs($this->bruno)->get(route('attention.show', $game))->assertOk();
    }

    public function test_other_households_cannot_see_or_change_a_room(): void
    {
        $game = $this->createGame();
        $outsider = $this->createOutsider();

        $this->actingAs($outsider)->get(route('attention.index'))
            ->assertInertia(fn (Assert $page) => $page->component('attention/index', false)->has('games', 0));
        $this->actingAs($outsider)->get(route('attention.show', $game))->assertNotFound();
        $this->actingAs($outsider)->get(route('attention.present', $game))->assertNotFound();
        $this->actingAs($outsider)->get(route('attention.export', $game))->assertNotFound();
        $this->actingAs($outsider)->post(route('attention.close', $game))->assertNotFound();
        $this->actingAs($outsider)->delete(route('attention.destroy', $game))->assertNotFound();

        $this->assertTrue($game->fresh()?->isOpen());
    }

    public function test_guests_cannot_see_the_reports(): void
    {
        $game = $this->createGame();

        $this->get(route('attention.index'))->assertRedirect(route('login'));
        $this->get(route('attention.show', $game))->assertRedirect(route('login'));
    }

    public function test_the_public_page_opens_without_an_account(): void
    {
        $game = $this->createGame();

        $this->get('/jogo/'.strtolower($game->code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('game/play', false)
                ->where('game.code', $game->code)
                ->where('game.open', true)
                ->has('types', 5)
                ->where('scoring.game_max', 5000));

        $this->get(route('game.landing', ['code' => strtolower($game->code)]))
            ->assertRedirect(route('game.show', $game->code));

        $this->get(route('game.landing', ['code' => 'NOPE1']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('game/join', false)
                ->where('error', 'Não encontramos uma sala com esse código.'));

        $this->get('/jogo/NOPE1')->assertNotFound();
    }

    public function test_players_join_with_a_nickname_unique_in_the_room(): void
    {
        $game = $this->createGame();

        $response = $this->postJson(route('game.join', $game->code), ['nickname' => '  Ana   P. '])
            ->assertCreated()
            ->assertJsonPath('player.nickname', 'Ana P.')
            ->assertJsonPath('player.score', 0)
            ->assertJsonPath('player.answered', []);

        $token = (string) $response->json('token');
        $this->assertSame(40, strlen($token));

        $player = AttentionPlayer::query()->sole();
        $this->assertSame(AttentionPlayer::hashToken($token), $player->token_hash);
        $this->assertArrayNotHasKey('token_hash', $player->toArray());

        $this->postJson(route('game.join', $game->code), ['nickname' => 'ana p.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname' => 'Esse apelido já está em uso nesta sala.']);

        $this->postJson(route('game.join', $game->code), ['nickname' => '<script>'])
            ->assertJsonValidationErrors('nickname');
    }

    public function test_a_full_game_adds_up_the_points_and_finishes_the_player(): void
    {
        $game = $this->createGame();
        $token = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');

        foreach (AttentionType::cases() as $type) {
            $overrides = $type === AttentionType::Divided ? ['guess' => AttentionType::Alternating->value] : [];
            $this->postJson(route('game.rounds.store', $game->code), ['token' => $token, ...$this->round($type, $overrides)])
                ->assertCreated()
                ->assertJsonPath('round.guess_correct', $type !== AttentionType::Divided);
        }

        $player = AttentionPlayer::query()->sole();
        $this->assertNotNull($player->finished_at);
        $this->assertSame(5, $player->correct_rounds);
        $this->assertSame(4800, $player->score);

        $this->postJson(route('game.resume', $game->code), ['token' => $token])
            ->assertOk()
            ->assertJsonPath('player.finished', true)
            ->assertJsonCount(5, 'player.answered');

        $this->getJson(route('game.ranking', $game->code))
            ->assertOk()
            ->assertJsonPath('ranking.0.nickname', 'Ana')
            ->assertJsonPath('ranking.0.score', 4800)
            ->assertJsonPath('finished', 1)
            ->assertJsonMissingPath('ranking.0.token_hash');
    }

    public function test_each_challenge_counts_once(): void
    {
        $game = $this->createGame();
        $token = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');
        $round = ['token' => $token, ...$this->round(AttentionType::Selective)];

        $this->postJson(route('game.rounds.store', $game->code), $round)->assertCreated();
        $this->postJson(route('game.rounds.store', $game->code), $round)
            ->assertJsonValidationErrors(['type' => 'Você já respondeu este desafio.']);

        $this->assertSame(1000, AttentionPlayer::query()->sole()->score);
    }

    public function test_rounds_need_the_token_of_a_player_of_that_room(): void
    {
        $game = $this->createGame();
        $other = $this->createGame('Outra sala');
        $token = (string) $this->postJson(route('game.join', $other->code), ['nickname' => 'Ana'])->json('token');

        $this->postJson(route('game.rounds.store', $game->code), ['token' => $token, ...$this->round(AttentionType::Selective)])
            ->assertNotFound();
        $this->postJson(route('game.rounds.store', $game->code), ['token' => str_repeat('x', 40), ...$this->round(AttentionType::Selective)])
            ->assertNotFound();
        $this->postJson(route('game.resume', $game->code), ['token' => $token])->assertNotFound();
    }

    public function test_measurements_are_validated(): void
    {
        $game = $this->createGame();
        $token = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');

        $this->postJson(route('game.rounds.store', $game->code), [
            'token' => $token,
            ...$this->round(AttentionType::Selective, ['accuracy' => 150, 'type' => 'telepathy', 'guess' => 'x']),
        ])->assertJsonValidationErrors(['accuracy', 'type', 'guess']);

        $this->postJson(route('game.rounds.store', $game->code), [
            'token' => $token,
            ...$this->round(AttentionType::Sustained, ['details' => ['expected' => 6, 'answer' => 5, 'secret' => 9]]),
        ])->assertCreated();

        $this->assertSame(['expected' => 6, 'answer' => 5], AttentionPlayer::query()->sole()->rounds()->sole()->details);
    }

    public function test_a_closed_room_takes_no_players_or_answers_until_reopened(): void
    {
        $game = $this->createGame();
        $token = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');

        $this->actingAs($this->bruno)->post(route('attention.close', $game))->assertRedirect();
        $this->assertFalse($game->fresh()?->isOpen());

        $this->postJson(route('game.join', $game->code), ['nickname' => 'Bia'])
            ->assertJsonValidationErrors(['game' => 'Esta sala foi encerrada.']);
        $this->postJson(route('game.rounds.store', $game->code), ['token' => $token, ...$this->round(AttentionType::Selective)])
            ->assertJsonValidationErrors('game');

        $this->actingAs($this->bruno)->delete(route('attention.reopen', $game))->assertRedirect();
        $this->postJson(route('game.join', $game->code), ['nickname' => 'Bia'])->assertCreated();
    }

    public function test_the_report_has_the_indicators_of_the_room(): void
    {
        $game = $this->createGame();
        $ana = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');
        $bia = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Bia'])->json('token');

        foreach (AttentionType::cases() as $type) {
            $this->postJson(route('game.rounds.store', $game->code), ['token' => $ana, ...$this->round($type)])->assertCreated();
        }

        $this->postJson(route('game.rounds.store', $game->code), ['token' => $bia, ...$this->round(AttentionType::Selective, [
            'correct' => false, 'accuracy' => 40, 'hits' => 3, 'errors' => 1, 'misses' => 2, 'duration_ms' => 20_000,
            'guess' => AttentionType::Voluntary->value,
        ])])->assertCreated();

        $this->actingAs($this->bruno)
            ->get(route('attention.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('attention/show', false)
                ->where('game.url', route('game.show', $game->code))
                ->where('summary.players', 2)
                ->where('summary.finished', 1)
                ->where('summary.playing', 1)
                ->where('summary.average_score', 5000)
                ->where('summary.success_rate', 83)
                ->where('summary.concept_rate', 83)
                ->where('types.0.type', 'selective')
                ->where('types.0.rounds', 2)
                ->where('types.0.success_rate', 50)
                ->where('types.0.average_accuracy', 70)
                ->where('types.0.guesses.selective', 1)
                ->where('types.0.guesses.voluntary', 1)
                ->where('types.0.average_errors', 0.5)
                ->where('players.0.nickname', 'Ana')
                ->where('players.0.position', 1)
                ->where('players.1.nickname', 'Bia')
                ->where('players.1.score', 200)
                ->where('players.1.finished', false)
                ->has('players.0.rounds', 5));

        $this->actingAs($this->bruno)
            ->get(route('attention.present', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('attention/present', false)
                ->where('qr', fn (string $svg) => str_starts_with($svg, '<svg'))
                ->has('ranking', 2));
    }

    public function test_the_results_export_as_a_spreadsheet(): void
    {
        $game = $this->createGame('Turma B');
        $token = (string) $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->json('token');
        $this->postJson(route('game.rounds.store', $game->code), ['token' => $token, ...$this->round(AttentionType::Divided, [
            'guess' => null, 'duration_ms' => 4_250, 'details' => ['expected' => 4, 'answer' => 4, 'expected_secondary' => 3, 'answer_secondary' => 2],
        ])])->assertCreated();

        $response = $this->actingAs($this->leticia)->get(route('attention.export', $game))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('jogo-da-atencao-turma-b-'.$game->code.'.csv', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\u{FEFF}Apelido;", $csv);
        $this->assertStringContainsString('Ana;', $csv);
        $this->assertStringContainsString(';"Duas coisas ao mesmo tempo";"Atenção dividida";Sim;100;5;0;0;4,3;;"Sem resposta";Não;', $csv);
    }

    public function test_deleting_a_room_removes_its_players(): void
    {
        $game = $this->createGame();
        $this->postJson(route('game.join', $game->code), ['nickname' => 'Ana'])->assertCreated();

        $this->actingAs($this->bruno)
            ->delete(route('attention.destroy', $game))
            ->assertRedirect(route('attention.index'));

        $this->assertDatabaseCount('attention_games', 0);
        $this->assertDatabaseCount('attention_players', 0);
    }
}
