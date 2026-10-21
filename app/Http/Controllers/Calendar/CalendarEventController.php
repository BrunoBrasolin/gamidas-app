<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\RecurrenceScope;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\CalendarEventRequest;
use App\Models\CalendarEvent;
use App\Services\CalendarService;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CalendarEventController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly CalendarService $calendar)
    {
        //
    }

    public function index(Request $request): Response
    {
        $month = Month::fromInput($request->query('month'));

        // The month grid runs from the Sunday on or before the 1st to the
        // Saturday on or after the last day, so adjacent days show events too.
        $from = $month->startOfWeek(CarbonInterface::SUNDAY);
        $to = $month->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY)->startOfDay();

        return Inertia::render('calendar/index', [
            'filters' => [
                'month' => Month::key($month),
                'view' => $request->query('view') === 'list' ? 'list' : 'calendar',
            ],
            'events' => $this->calendar->between($this->household($request), $from, $to),
        ]);
    }

    public function store(CalendarEventRequest $request): RedirectResponse
    {
        $this->calendar->create($this->household($request), $this->user($request), $request->toSeriesData());

        $this->toast('Evento adicionado.');

        return back();
    }

    public function update(CalendarEventRequest $request, CalendarEvent $event): RedirectResponse
    {
        $occurrence = $event->isRecurring() ? $request->occurrence() : null;

        if ($occurrence !== null) {
            $this->calendar->updateOccurrence($event, $occurrence, $this->user($request), $request->toData());
        } else {
            $this->calendar->updateSeries($event, $this->user($request), $request->toSeriesData());
        }

        $this->toast($event->isRecurring() && $occurrence === null ? 'Série de eventos atualizada.' : 'Evento atualizado.');

        return back();
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $validated = $request->validate([
            'scope' => ['nullable', Rule::enum(RecurrenceScope::class)],
            'occurrence' => ['nullable', 'required_if:scope,occurrence', 'date_format:Y-m-d'],
        ]);

        if ($event->isRecurring() && ($validated['scope'] ?? null) === RecurrenceScope::Occurrence->value) {
            $occurrence = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['occurrence'], config('app.timezone'));
            $this->calendar->cancelOccurrence($event, $occurrence, $this->user($request));
            $this->toast('Evento excluído desta data.');

            return back();
        }

        $event->delete();

        $this->toast($event->isRecurring() ? 'Série de eventos excluída.' : 'Evento excluído.');

        return back();
    }
}
