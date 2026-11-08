<?php

namespace App\Http\Controllers\Roulette;

use App\Enums\RouletteCategory;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roulette\RoulettePlaceRequest;
use App\Models\RoulettePlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoulettePlaceController extends Controller
{
    use InteractsWithHousehold;

    /**
     * The roulette with every category's places. The draw itself happens in
     * the browser, which spins the wheel and lands on a random slice.
     */
    public function index(Request $request): Response
    {
        $places = $this->household($request)->roulettePlaces()->with('creator')->get();

        return Inertia::render('roulette/index', [
            'categories' => collect(RouletteCategory::cases())->map(fn (RouletteCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'places' => $places->where('category', $category)->map->toPresenter()->values(),
            ])->values(),
        ]);
    }

    public function store(RoulettePlaceRequest $request): RedirectResponse
    {
        $this->household($request)->roulettePlaces()->create([
            'category' => $request->category(),
            'name' => $request->validated('name'),
            'created_by_user_id' => $request->user()?->id,
        ]);

        $this->toast('Lugar adicionado à roleta.');

        return back();
    }

    public function destroy(RoulettePlace $place): RedirectResponse
    {
        Gate::authorize('delete', $place);

        $place->delete();

        $this->toast('Lugar removido da roleta.');

        return back();
    }
}
