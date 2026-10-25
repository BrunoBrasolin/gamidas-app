<?php

namespace App\Http\Controllers\Shows;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shows\ShowChecklistRequest;
use App\Models\Show;
use App\Models\ShowChecklistItem;
use App\Services\ShowChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ShowChecklistController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly ShowChecklistService $checklist)
    {
        //
    }

    public function edit(Request $request): Response
    {
        return Inertia::render('shows/checklist', [
            'items' => $this->household($request)->showChecklistItems()->pluck('label'),
        ]);
    }

    public function update(ShowChecklistRequest $request): RedirectResponse
    {
        $this->checklist->sync($this->household($request), $request->labels());

        $this->toast('Checklist salvo.');

        return to_route('shows.checklist.edit');
    }

    /**
     * Check or uncheck one item of the checklist for a show.
     */
    public function check(Request $request, Show $show, ShowChecklistItem $item): RedirectResponse
    {
        Gate::authorize('update', $show);
        abort_unless($item->household_id === $show->household_id, 404);

        $validated = $request->validate(['checked' => ['required', 'boolean']]);

        $this->checklist->check($show, $item, (bool) $validated['checked']);

        return back();
    }
}
