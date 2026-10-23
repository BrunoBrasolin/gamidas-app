<?php

namespace App\Http\Controllers\Time;

use App\Enums\TaskStatus;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Time\TaskRequest;
use App\Models\Task;
use App\Services\TaskService;
use App\Services\TimeSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TaskService $tasks)
    {
        //
    }

    public function index(Request $request, TimeSummary $summary): Response
    {
        $user = $this->user($request);
        $status = TaskStatus::tryFrom((string) $request->query('status')) ?? TaskStatus::Active;

        $tasks = $user->tasks()
            ->withStatus($status)
            ->when($status === TaskStatus::Active, fn ($query) => $query->orderByRaw('deadline is null')->orderBy('deadline'))
            ->orderBy('title')
            ->get();

        $counts = [];
        foreach (TaskStatus::cases() as $case) {
            $counts[$case->value] = $user->tasks()->withStatus($case)->count();
        }

        return Inertia::render('time/tasks', [
            'filters' => ['status' => $status->value],
            'counts' => $counts,
            'tasks' => $summary->tasks($tasks),
        ]);
    }

    public function store(TaskRequest $request): RedirectResponse
    {
        $this->tasks->create($this->user($request), $request->toData());

        $this->toast('Tarefa criada.');

        return back();
    }

    public function update(TaskRequest $request, Task $task): RedirectResponse
    {
        $this->tasks->update($task, $request->toData());

        $this->toast('Tarefa atualizada.');

        return back();
    }

    public function complete(Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $this->tasks->complete($task);

        $this->toast('Tarefa concluída.');

        return back();
    }

    public function reopen(Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $this->tasks->reopen($task);

        $this->toast('Tarefa reaberta.');

        return back();
    }

    public function archive(Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $stopped = $this->tasks->archive($task);

        $this->toast($stopped > 0 ? 'Tarefa arquivada e cronômetro parado.' : 'Tarefa arquivada.');

        return back();
    }

    public function restore(Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $this->tasks->restore($task);

        $this->toast('Tarefa restaurada.');

        return back();
    }

    public function destroy(Task $task): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $this->tasks->delete($task);

        $this->toast('Tarefa excluída.');

        return back();
    }
}
