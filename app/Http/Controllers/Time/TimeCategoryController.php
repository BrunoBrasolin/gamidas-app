<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\TimeCategoryRequest;
use App\Models\TimeCategory;
use App\Services\TimeCategoryService;
use App\Services\TimeSummary;
use App\Support\Day;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TimeCategoryController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TimeCategoryService $categories)
    {
        //
    }

    public function index(Request $request, TimeSummary $summary): Response
    {
        $user = $this->user($request);
        $today = Day::today();
        $month = $summary->secondsByCategory($user, $today->startOfMonth(), $today);

        $all = $user->timeCategories()->withCount('logs')->orderByRaw('archived_at is not null')->orderBy('name')->get();

        return Inertia::render('time/categories', [
            'categories' => $all->map(fn (TimeCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'daily_goal_seconds' => $category->daily_goal_seconds,
                'archived' => $category->isArchived(),
                'has_history' => $category->getAttribute('logs_count') > 0,
                'month_seconds' => $month[$category->id] ?? 0,
            ])->values(),
        ]);
    }

    public function store(TimeCategoryRequest $request): RedirectResponse
    {
        $this->categories->create($this->user($request), (string) $request->validated('name'), $request->validated('daily_goal_seconds'));

        $this->toast('Categoria criada.');

        return back();
    }

    public function update(TimeCategoryRequest $request, TimeCategory $category): RedirectResponse
    {
        $this->categories->rename($category, (string) $request->validated('name'), $request->validated('daily_goal_seconds'));

        $this->toast('Categoria atualizada.');

        return back();
    }

    public function archive(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->categories->archive($category);

        $this->toast('Categoria arquivada.');

        return back();
    }

    public function restore(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->categories->restore($category);

        $this->toast('Categoria restaurada.');

        return back();
    }

    public function destroy(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $this->categories->delete($category);

        $this->toast('Categoria excluída.');

        return back();
    }
}
