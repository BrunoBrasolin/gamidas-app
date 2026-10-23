<?php

namespace App\Http\Controllers\Time;

use App\Enums\TimerStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TimerSession;
use App\Services\TimerService;
use App\Support\Day;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TimerController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TimerService $timer)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $taskId = $request->integer('task') ?: null;
        $now = Date::now();
        $today = Day::today();

        $sessions = $user->timerSessions()
            ->with('task')
            ->where('status', TimerStatus::Stopped)
            ->when($taskId !== null, fn ($query) => $query->where('task_id', $taskId))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('time/timer', [
            'filters' => ['task' => $taskId],
            'open_sessions' => $this->timer->open($user)->map(fn (TimerSession $session) => $session->toPage($now))->values(),
            'sessions' => $sessions->through(fn (TimerSession $session) => $session->toPage($now)),
            'tasks' => $user->tasks()->whereNull('archived_at')->orderBy('title')->get()->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status()->value,
            ])->values(),
            'all_tasks' => $user->tasks()->orderBy('title')->get(['id', 'title']),
            'measured' => [
                'today_seconds' => $this->timer->measuredSeconds($user, $today, $today->endOfDay()),
                'week_seconds' => $this->timer->measuredSeconds($user, Day::startOfWeek($today), $today->endOfDay()),
            ],
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'task_id' => ['required', 'integer', Rule::exists('tasks', 'id')->where('user_id', $user->id)],
            'existing' => ['nullable', Rule::in([TimerService::PAUSE_EXISTING, TimerService::STOP_EXISTING])],
        ], attributes: ['task_id' => 'tarefa']);

        $task = $user->tasks()->findOrFail((int) $validated['task_id']);
        $this->timer->start($user, $task, $validated['existing'] ?? null);

        $this->toast("Cronômetro iniciado: {$task->title}.");

        return back();
    }

    public function pause(TimerSession $session): RedirectResponse
    {
        Gate::authorize('update', $session);

        $this->toast(...$this->timer->pause($session)
            ? ['Cronômetro pausado.']
            : ['Este cronômetro já não estava em andamento.', 'info']);

        return back();
    }

    public function resume(Request $request, TimerSession $session): RedirectResponse
    {
        Gate::authorize('update', $session);

        $validated = $request->validate([
            'existing' => ['nullable', Rule::in([TimerService::PAUSE_EXISTING, TimerService::STOP_EXISTING])],
        ]);

        $this->toast(...$this->timer->resume($session, $validated['existing'] ?? null)
            ? ['Cronômetro retomado.']
            : ['Este cronômetro já estava em andamento.', 'info']);

        return back();
    }

    public function stop(TimerSession $session): RedirectResponse
    {
        Gate::authorize('update', $session);

        $this->toast(...$this->timer->stop($session)
            ? ['Cronômetro parado. A sessão não foi lançada na planilha.']
            : ['Este cronômetro já tinha sido parado.', 'info']);

        return back();
    }

    public function destroy(TimerSession $session): RedirectResponse
    {
        Gate::authorize('delete', $session);

        if ($session->isOpen()) {
            throw ValidationException::withMessages(['timer' => 'Pare o cronômetro antes de excluir a sessão.']);
        }

        $session->delete();

        $this->toast('Sessão do cronômetro excluída.');

        return back();
    }
}
