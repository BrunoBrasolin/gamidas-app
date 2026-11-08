<?php

namespace App\Http\Controllers\ComplementaryHours;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComplementaryHours\ComplementaryActivityRequest;
use App\Http\Requests\ComplementaryHours\ComplementaryHoursGoalRequest;
use App\Models\ComplementaryActivity;
use App\Models\ComplementaryActivityAttachment;
use App\Services\ComplementaryHoursService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplementaryActivityController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly ComplementaryHoursService $hours)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $activities = $user->complementaryActivities()
            ->with('attachments')
            ->orderByRaw('completed_on is null')
            ->orderByDesc('completed_on')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('complementary-hours/index', [
            'summary' => $this->hours->summary($user),
            'activities' => $activities->map(fn (ComplementaryActivity $activity) => $this->present($activity))->values(),
        ]);
    }

    public function goal(ComplementaryHoursGoalRequest $request): RedirectResponse
    {
        $this->hours->setGoal($this->user($request), $request->minutes());

        $this->toast('Meta de horas atualizada.');

        return to_route('complementary-hours.index');
    }

    public function create(): Response
    {
        return Inertia::render('complementary-hours/form', [
            'activity' => null,
            ...$this->limits(),
        ]);
    }

    public function store(ComplementaryActivityRequest $request): RedirectResponse
    {
        $activity = $this->hours->create($this->user($request), $request->activity(), $request->uploads());

        $this->toast('Atividade cadastrada.');

        return to_route('complementary-hours.show', $activity);
    }

    public function show(ComplementaryActivity $activity): Response
    {
        Gate::authorize('view', $activity);

        return Inertia::render('complementary-hours/show', [
            'activity' => $this->present($activity),
        ]);
    }

    public function edit(ComplementaryActivity $activity): Response
    {
        Gate::authorize('update', $activity);

        return Inertia::render('complementary-hours/form', [
            'activity' => $this->present($activity),
            ...$this->limits(),
        ]);
    }

    public function update(ComplementaryActivityRequest $request, ComplementaryActivity $activity): RedirectResponse
    {
        $this->hours->update($activity, $request->activity(), $request->uploads(), $request->removedAttachmentIds());

        $this->toast('Atividade atualizada.');

        return to_route('complementary-hours.show', $activity);
    }

    public function destroy(ComplementaryActivity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        $this->hours->delete($activity);

        $this->toast('Atividade excluída.');

        return to_route('complementary-hours.index');
    }

    /**
     * Serve an attachment to the activity's owner only, inline unless
     * ?download=1. The stored type was detected on upload, and nosniff stops
     * the browser from guessing another one.
     */
    public function attachment(Request $request, ComplementaryActivity $activity, ComplementaryActivityAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $activity);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $headers = [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800',
        ];

        return $request->boolean('download')
            ? $disk->download($attachment->path, $attachment->filename, $headers)
            : $disk->response($attachment->path, $attachment->filename, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ComplementaryActivity $activity): array
    {
        $attachments = $activity->attachments
            ->sortBy([
                fn (ComplementaryActivityAttachment $a, ComplementaryActivityAttachment $b) => $a->kind->position() <=> $b->kind->position(),
                fn (ComplementaryActivityAttachment $a, ComplementaryActivityAttachment $b) => $a->id <=> $b->id,
            ]);

        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'completed_on' => $activity->completed_on?->toDateString(),
            'minutes' => $activity->minutes,
            'report_done' => $activity->report_done,
            'attachments' => $attachments->map(fn (ComplementaryActivityAttachment $attachment) => [
                'id' => $attachment->id,
                'kind' => $attachment->kind->value,
                'name' => $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'is_image' => $attachment->isImage(),
                'is_pdf' => $attachment->isPdf(),
            ])->values(),
        ];
    }

    /**
     * @return array{maxAttachmentMb: int, maxUploads: int, maxAttachments: int}
     */
    private function limits(): array
    {
        return [
            'maxAttachmentMb' => intdiv((int) config('complementary_hours.max_attachment_kb'), 1024),
            'maxUploads' => (int) config('complementary_hours.max_uploads'),
            'maxAttachments' => (int) config('complementary_hours.max_attachments'),
        ];
    }
}
