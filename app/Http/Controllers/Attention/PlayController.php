<?php

namespace App\Http\Controllers\Attention;

use App\Enums\AttentionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\AttentionPlayerRequest;
use App\Http\Requests\Attention\AttentionRoundRequest;
use App\Http\Requests\Attention\JoinAttentionGameRequest;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Services\Attention\AttentionGameService;
use App\Services\Attention\AttentionReport;
use App\Services\Attention\AttentionScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of the attention game, played on phones without an
 * account. The page is rendered by Inertia; joining and answering are JSON
 * requests authenticated by the player's token.
 */
class PlayController extends Controller
{
    public function __construct(private readonly AttentionGameService $games)
    {
        //
    }

    /**
     * Where players type the room code (or get sent with ?code=).
     */
    public function landing(Request $request): Response|RedirectResponse
    {
        $code = strtoupper(trim((string) $request->query('code', '')));

        if ($code !== '') {
            $game = AttentionGame::query()->where('code', $code)->first();

            if ($game !== null) {
                return to_route('game.show', $game->code);
            }
        }

        return Inertia::render('game/join', [
            'code' => $code,
            'error' => $code === '' ? null : 'Não encontramos uma sala com esse código.',
        ]);
    }

    public function show(AttentionGame $game): Response
    {
        return Inertia::render('game/play', [
            'game' => [
                'title' => $game->title,
                'code' => $game->code,
                'open' => $game->isOpen(),
            ],
            'types' => array_map(fn (AttentionType $type) => ['value' => $type->value, 'label' => $type->label()], AttentionType::cases()),
            'scoring' => [
                'round_max' => AttentionScore::ROUND_MAX,
                'game_max' => AttentionScore::gameMax(),
                'concept' => AttentionScore::CONCEPT,
            ],
        ]);
    }

    public function join(JoinAttentionGameRequest $request, AttentionGame $game): JsonResponse
    {
        [$player, $token] = $this->games->join($game, $request->nickname());

        return response()->json(['player' => $this->player($player), 'token' => $token], 201);
    }

    /**
     * Pick up where the phone left off (page reloaded mid-game).
     */
    public function resume(AttentionPlayerRequest $request, AttentionGame $game): JsonResponse
    {
        return response()->json(['player' => $this->player($this->authenticate($request, $game))]);
    }

    public function round(AttentionRoundRequest $request, AttentionGame $game): JsonResponse
    {
        $player = $this->authenticate($request, $game);
        $round = $this->games->recordRound($player, $request->round());

        return response()->json([
            'round' => [
                'type' => $round->type->value,
                'correct' => $round->correct,
                'guess_correct' => $round->guess_correct,
                'performance_points' => $round->performance_points,
                'points' => $round->points,
            ],
            'player' => $this->player($player),
        ], 201);
    }

    public function ranking(AttentionGame $game): JsonResponse
    {
        $report = new AttentionReport($game);
        $summary = $report->summary();

        return response()->json([
            'ranking' => $report->ranking(),
            'players' => $summary['players'],
            'finished' => $summary['finished'],
            'open' => $game->isOpen(),
        ]);
    }

    private function authenticate(AttentionPlayerRequest $request, AttentionGame $game): AttentionPlayer
    {
        $player = $this->games->findPlayer($game, $request->token());

        abort_if($player === null, 404, 'Jogador não encontrado nesta sala.');

        return $player;
    }

    /**
     * @return array{id: int, nickname: string, score: int, correct_rounds: int, finished: bool, answered: list<string>}
     */
    private function player(AttentionPlayer $player): array
    {
        return [
            'id' => $player->id,
            'nickname' => $player->nickname,
            'score' => $player->score,
            'correct_rounds' => $player->correct_rounds,
            'finished' => $player->finished_at !== null,
            'answered' => array_values($player->rounds()->pluck('type')->map(fn (AttentionType $type) => $type->value)->all()),
        ];
    }
}
