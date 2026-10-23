<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\TimeLogRequest;
use App\Services\TimeLogService;
use Illuminate\Http\RedirectResponse;

class TimeLogController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TimeLogService $logs)
    {
        //
    }

    /**
     * Save (or clear) the cell of a category on a date.
     */
    public function update(TimeLogRequest $request): RedirectResponse
    {
        $log = $this->logs->save(
            $request->category(),
            $request->day('log_date'),
            (int) $request->validated('seconds'),
            $request->validated('note'),
            $request->has('note'),
        );

        $this->toast($log === null ? 'Lançamento removido.' : 'Lançamento salvo.');

        return back();
    }
}
