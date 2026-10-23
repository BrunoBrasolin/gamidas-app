<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\DailyNoteRequest;
use App\Models\DailyNote;
use App\Services\DailyNoteService;
use App\Support\Day;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyNoteController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly DailyNoteService $notes)
    {
        //
    }

    /**
     * Search the user's notes by text.
     */
    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('q')), 0, 100);

        $notes = DailyNote::query()
            ->where('user_id', $this->user($request)->id)
            // "!" escapes LIKE wildcards the same way on MySQL and SQLite.
            ->when($search !== '', fn ($query) => $query->whereRaw(
                "content like ? escape '!'",
                ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'],
            ))
            ->orderByDesc('note_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('time/notes', [
            'filters' => ['q' => $search],
            'notes' => $notes->through(fn (DailyNote $note) => [
                'date' => $note->note_date->toDateString(),
                'preview' => $note->preview(240),
                'updated_at' => $note->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * The note of a day as JSON, for editors opened from the time sheet.
     */
    public function show(Request $request, string $date): JsonResponse
    {
        $day = Day::fromRoute($date);

        return response()->json(DailyNoteService::toPage($day, $this->notes->find($this->user($request), $day)));
    }

    /**
     * Autosave. Answers 409 with the stored note when it was changed
     * elsewhere since the revision the editor started from.
     */
    public function update(DailyNoteRequest $request, string $date): JsonResponse
    {
        $day = Day::fromRoute($date);
        $result = $this->notes->save(
            $this->user($request),
            $day,
            (string) $request->validated('content'),
            (int) $request->validated('base_revision'),
        );

        return response()->json(DailyNoteService::toPage($day, $result['note']), $result['saved'] ? 200 : 409);
    }
}
