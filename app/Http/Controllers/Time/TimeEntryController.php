<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\SheetCellRequest;
use App\Http\Requests\Time\TimeEntryRequest;
use App\Models\TimeEntry;
use App\Services\TimeEntryService;
use App\Support\Day;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Manual time entries: the explicit, user-controlled record of time spent.
 */
class TimeEntryController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TimeEntryService $entries)
    {
        //
    }

    public function store(TimeEntryRequest $request): RedirectResponse
    {
        $this->entries->create($this->user($request), $request->toData());

        $this->toast('Tempo lançado.');

        return back();
    }

    public function update(TimeEntryRequest $request, TimeEntry $entry): RedirectResponse
    {
        $this->entries->update($entry, $request->toData());

        $this->toast('Lançamento atualizado.');

        return back();
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        Gate::authorize('delete', $entry);

        $entry->delete();

        $this->toast('Lançamento excluído.');

        return back();
    }

    /**
     * Inline edit of a time-sheet cell.
     */
    public function cell(SheetCellRequest $request): RedirectResponse
    {
        $this->entries->setCell(
            $this->user($request),
            $request->task(),
            $request->day('entry_date'),
            (int) $request->validated('minutes'),
        );

        return back();
    }

    /**
     * Copy the entries of an earlier day to the given day.
     */
    public function copy(Request $request, string $date): RedirectResponse
    {
        $target = Day::fromRoute($date);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'before:'.$target->toDateString(), 'after_or_equal:'.Day::MIN],
        ], attributes: ['from' => 'dia de origem']);

        $source = Day::tryParse($validated['from']) ?? throw ValidationException::withMessages(['from' => 'Data inválida.']);
        $created = $this->entries->copyDay($this->user($request), $source, $target);

        $this->toast(match ($created) {
            0 => 'Nada a copiar: os lançamentos já estavam no dia.',
            1 => '1 lançamento copiado.',
            default => "{$created} lançamentos copiados.",
        }, $created === 0 ? 'info' : 'success');

        return back();
    }
}
