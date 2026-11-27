<?php

namespace App\Http\Controllers\Shows;

use App\Enums\TierListKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tiers\TierListRequest;
use App\Http\Requests\Tiers\TierRequest;
use App\Models\Show;
use App\Services\ShowTierService;
use App\Services\TierListShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's own tier list of the household's shows.
 */
class ShowTierController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly ShowTierService $tiers)
    {
        //
    }

    public function index(Request $request, TierListShareService $shares): Response
    {
        $user = $this->user($request);

        return Inertia::render('shows/tiers', [
            'tiers' => array_map(
                fn (array $shows) => array_map(fn (Show $show) => $show->id, $shows),
                $this->tiers->tiers($user),
            ),
            'shows' => $this->tiers->rankable($user)->map(fn (Show $show) => [
                'id' => $show->id,
                'band' => $show->band,
                'tour' => $show->tour,
                'venue' => $show->venue,
                'date' => $show->show_date->toDateString(),
            ])->values(),
            'shareUrl' => $shares->url($user, TierListKind::Shows),
        ]);
    }

    public function update(TierListRequest $request): RedirectResponse
    {
        $this->tiers->setTiers($this->user($request), $request->tiers());

        return back();
    }

    /**
     * Put one show in a tier (at the end) or take it off the tier list.
     */
    public function tier(TierRequest $request, Show $show): RedirectResponse
    {
        Gate::authorize('view', $show);

        $this->tiers->setTier($this->user($request), $show, $request->tier());

        return back();
    }
}
