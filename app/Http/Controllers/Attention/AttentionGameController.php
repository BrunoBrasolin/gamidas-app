<?php

namespace App\Http\Controllers\Attention;

use App\Enums\AttentionType;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\AttentionGameRequest;
use App\Models\AttentionGame;
use App\Models\AttentionPlayer;
use App\Models\AttentionRound;
use App\Services\Attention\AttentionGameService;
use App\Services\Attention\AttentionReport;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The household's rooms of the attention game: creating them, following the
 * players live (report and projector screen) and exporting the results.
 */
class AttentionGameController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly AttentionGameService $games)
    {
        //
    }

    public function index(Request $request): Response
    {
        $games = $this->household($request)->attentionGames()
            ->with('creator:id,name')
            ->withCount([
                'players',
                'players as finished_count' => fn ($query) => $query->whereNotNull('finished_at'),
            ])
            ->latest()
            ->orderByDesc('id')
            ->get();

        return Inertia::render('attention/index', [
            'games' => $games->map(fn (AttentionGame $game) => [
                ...$this->presentGame($game),
                'players' => (int) $game->getAttribute('players_count'),
                'finished' => (int) $game->getAttribute('finished_count'),
            ])->values(),
        ]);
    }

    public function store(AttentionGameRequest $request): RedirectResponse
    {
        $game = $this->games->create($this->household($request), $this->user($request), (string) $request->validated('title'));

        $this->toast('Sala criada. Compartilhe o link ou o QR Code.');

        return to_route('attention.show', $game);
    }

    public function show(AttentionGame $game): Response
    {
        Gate::authorize('view', $game);
        $report = new AttentionReport($game);

        return Inertia::render('attention/show', [
            'game' => $this->presentGame($game),
            'summary' => $report->summary(),
            'types' => $report->types(),
            'players' => $report->players(),
        ]);
    }

    /**
     * Full-screen page for the projector: QR code, live counters and ranking.
     */
    public function present(AttentionGame $game): Response
    {
        Gate::authorize('view', $game);
        $report = new AttentionReport($game);

        return Inertia::render('attention/present', [
            'game' => $this->presentGame($game),
            'qr' => $this->qrCode($this->playUrl($game)),
            'summary' => $report->summary(),
            'types' => $report->types(),
            'ranking' => $report->ranking(10),
        ]);
    }

    /**
     * Every round of every player, as a spreadsheet (semicolon separated,
     * with a BOM so Excel in Portuguese opens it with accents).
     */
    public function export(AttentionGame $game): StreamedResponse
    {
        Gate::authorize('view', $game);
        $players = $game->players()->with('rounds')->get();
        $filename = 'jogo-da-atencao-'.Str::slug($game->title).'-'.$game->code.'.csv';

        return response()->streamDownload(function () use ($players) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fwrite($out, "\u{FEFF}");
            fputcsv($out, [
                'Apelido', 'Entrou em', 'Concluiu', 'Pontuação total', 'Desafio', 'Tipo de atenção', 'Acertou o desafio',
                'Precisão (%)', 'Acertos', 'Erros', 'Omissões', 'Tempo (s)', 'Reação média (ms)', 'Tipo escolhido',
                'Identificou o tipo', 'Pontos', 'Esperado', 'Resposta', 'Esperado (2ª)', 'Resposta (2ª)', 'Perseverações',
            ], ';', escape: '');

            foreach ($players as $player) {
                /** @var AttentionPlayer $player */
                foreach ($player->rounds as $round) {
                    /** @var AttentionRound $round */
                    $details = $round->details ?? [];
                    fputcsv($out, [
                        $player->nickname,
                        $player->created_at?->format('d/m/Y H:i:s'),
                        $player->finished_at === null ? 'Não' : 'Sim',
                        $player->score,
                        $round->type->challenge(),
                        $round->type->label(),
                        $round->correct ? 'Sim' : 'Não',
                        $round->accuracy,
                        $round->hits,
                        $round->errors,
                        $round->misses,
                        number_format($round->duration_ms / 1000, 1, ',', ''),
                        $round->reaction_ms,
                        $round->guess?->label() ?? 'Sem resposta',
                        $round->guess_correct ? 'Sim' : 'Não',
                        $round->points,
                        $details['expected'] ?? null,
                        $details['answer'] ?? null,
                        $details['expected_secondary'] ?? null,
                        $details['answer_secondary'] ?? null,
                        $details['perseverations'] ?? null,
                    ], ';', escape: '');
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function close(AttentionGame $game): RedirectResponse
    {
        Gate::authorize('update', $game);

        $this->games->close($game);

        $this->toast('Sala encerrada. Ninguém mais consegue jogar.');

        return back();
    }

    public function reopen(AttentionGame $game): RedirectResponse
    {
        Gate::authorize('update', $game);

        $this->games->reopen($game);

        $this->toast('Sala reaberta.');

        return back();
    }

    public function destroy(AttentionGame $game): RedirectResponse
    {
        Gate::authorize('delete', $game);

        $this->games->delete($game);

        $this->toast('Sala excluída.');

        return to_route('attention.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGame(AttentionGame $game): array
    {
        return [
            'id' => $game->id,
            'title' => $game->title,
            'code' => $game->code,
            'url' => $this->playUrl($game),
            'open' => $game->isOpen(),
            'created_at' => $game->created_at?->toIso8601String(),
            'closed_at' => $game->closed_at?->toIso8601String(),
            'created_by' => $game->creator?->firstName(),
            'types' => array_map(fn (AttentionType $type) => ['value' => $type->value, 'label' => $type->label()], AttentionType::cases()),
        ];
    }

    private function playUrl(AttentionGame $game): string
    {
        return route('game.show', $game->code);
    }

    private function qrCode(string $url): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(320, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(17, 24, 39))),
                new SvgImageBackEnd,
            ),
        ))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
