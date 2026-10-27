<?php

namespace App\Http\Controllers\ComplementaryHours;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\ComplementaryHours\ComplementaryActivityRequest;
use App\Http\Requests\ComplementaryHours\ComplementaryHoursGoalRequest;
use App\Models\ComplementaryActivity;
use App\Services\ComplementaryHoursService;
use App\Services\ComplementaryReportPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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
            'maxCertificateMb' => $this->maxCertificateMb(),
        ]);
    }

    public function store(ComplementaryActivityRequest $request): RedirectResponse
    {
        /** @var array{mime: string, extension: string} $info */
        $info = $request->certificateInfo();

        $activity = $this->hours->create(
            $this->user($request),
            $request->activity(),
            $request->certificate() ?? abort(422),
            $info,
        );

        $this->toast('Atividade cadastrada.');

        return to_route('complementary-hours.show', $activity);
    }

    public function show(ComplementaryActivity $activity): Response
    {
        Gate::authorize('view', $activity);

        return Inertia::render('complementary-hours/show', [
            'activity' => $this->present($activity, withReport: true),
        ]);
    }

    public function edit(ComplementaryActivity $activity): Response
    {
        Gate::authorize('update', $activity);

        return Inertia::render('complementary-hours/form', [
            'activity' => $this->present($activity, withReport: true),
            'maxCertificateMb' => $this->maxCertificateMb(),
        ]);
    }

    public function update(ComplementaryActivityRequest $request, ComplementaryActivity $activity): RedirectResponse
    {
        $this->hours->update($activity, $request->activity(), $request->certificate(), $request->certificateInfo());

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
     * Serve the certificate to its owner only, inline unless ?download=1. The
     * stored type was detected on upload, and nosniff stops the browser from
     * guessing another one.
     */
    public function certificate(Request $request, ComplementaryActivity $activity): StreamedResponse
    {
        Gate::authorize('view', $activity);

        $disk = Storage::disk($activity->certificate_disk);

        abort_unless($disk->exists($activity->certificate_path), 404);

        $headers = [
            'Content-Type' => $activity->certificate_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=604800',
        ];

        return $request->boolean('download')
            ? $disk->download($activity->certificate_path, $activity->certificate_filename, $headers)
            : $disk->response($activity->certificate_path, $activity->certificate_filename, $headers);
    }

    /**
     * Download the report as a PDF.
     */
    public function report(ComplementaryActivity $activity, ComplementaryReportPdf $pdf): HttpResponse
    {
        Gate::authorize('view', $activity);

        abort_unless($activity->hasReport(), 404);

        return response($pdf->render($activity), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($activity).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ComplementaryActivity $activity, bool $withReport = false): array
    {
        return [
            'id' => $activity->id,
            'title' => $activity->title,
            'completed_on' => $activity->completed_on?->toDateString(),
            'minutes' => $activity->minutes,
            'has_report' => $activity->hasReport(),
            'report' => $withReport ? $activity->report : null,
            'certificate' => [
                'name' => $activity->certificate_filename,
                'mime' => $activity->certificate_mime,
                'size' => $activity->certificate_size,
                'is_image' => $activity->certificateIsImage(),
            ],
        ];
    }

    private function maxCertificateMb(): int
    {
        return intdiv((int) config('complementary_hours.max_certificate_kb'), 1024);
    }
}
