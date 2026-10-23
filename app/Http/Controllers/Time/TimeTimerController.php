<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\TimeCategory;
use App\Services\TimeTimerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Start, pause, reset and commit the stopwatch of one of the user's categories.
 */
class TimeTimerController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TimeTimerService $timers)
    {
        //
    }

    public function start(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->timers->start($category);

        return back();
    }

    public function pause(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->timers->pause($category);

        return back();
    }

    public function reset(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $this->timers->reset($category);

        return back();
    }

    public function commit(TimeCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $added = $this->timers->commit($category);

        $this->toast($added > 0 ? 'Tempo lançado na planilha de hoje.' : 'O cronômetro estava zerado.');

        return back();
    }
}
