<?php

namespace App\Http\Controllers\Shows;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shows\ShowRequest;
use App\Models\Show;
use App\Models\ShowOpeningAct;
use App\Services\ShowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly ShowService $shows)
    {
        //
    }

    public function index(Request $request): Response
    {
        $shows = $this->household($request)->shows()
            ->with(['openingActs', 'creator:id,name'])
            ->orderByDesc('show_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('shows/index', [
            'shows' => $shows->map(fn (Show $show) => $this->present($show))->values(),
            'stats' => $this->stats($shows),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('shows/form', ['show' => null]);
    }

    public function store(ShowRequest $request): RedirectResponse
    {
        $show = $this->shows->create($this->household($request), $this->user($request), $request->show());

        $this->toast('Show cadastrado.');

        return to_route('shows.show', $show);
    }

    public function show(Show $show): Response
    {
        Gate::authorize('view', $show);
        $show->load('creator:id,name');

        return Inertia::render('shows/show', ['show' => $this->present($show)]);
    }

    public function edit(Show $show): Response
    {
        Gate::authorize('update', $show);

        return Inertia::render('shows/form', ['show' => $this->present($show)]);
    }

    public function update(ShowRequest $request, Show $show): RedirectResponse
    {
        $this->shows->update($show, $request->show());

        $this->toast('Show atualizado.');

        return to_route('shows.show', $show);
    }

    public function destroy(Show $show): RedirectResponse
    {
        Gate::authorize('delete', $show);

        $this->shows->delete($show);

        $this->toast('Show excluído.');

        return to_route('shows.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Show $show): array
    {
        return [
            'id' => $show->id,
            'band' => $show->band,
            'tour' => $show->tour,
            'venue' => $show->venue,
            'date' => $show->show_date->toDateString(),
            'setlist' => $show->setlist,
            'photos_url' => $show->photos_url,
            'created_by' => $show->creator?->firstName(),
            'opening_acts' => $show->openingActs->map(fn (ShowOpeningAct $act) => [
                'band' => $act->band,
                'setlist' => $act->setlist,
            ])->values(),
        ];
    }

    /**
     * Totals over every show. Bands count headliners and opening acts once
     * each, ignoring case and accents.
     *
     * @param  Collection<int, Show>  $shows
     * @return array{shows: int, bands: int, venues: int, songs: int}
     */
    private function stats(Collection $shows): array
    {
        $key = fn (string $name) => Str::lower(Str::ascii(trim($name)));

        $bands = $shows->flatMap(fn (Show $show) => [
            $show->band,
            ...$show->openingActs->pluck('band'),
        ])->map($key)->unique();

        return [
            'shows' => $shows->count(),
            'bands' => $bands->count(),
            'venues' => $shows->map(fn (Show $show) => $key($show->venue))->unique()->count(),
            'songs' => $shows->sum(fn (Show $show) => count($show->setlist)
                + $show->openingActs->sum(fn (ShowOpeningAct $act) => count($act->setlist))),
        ];
    }
}
