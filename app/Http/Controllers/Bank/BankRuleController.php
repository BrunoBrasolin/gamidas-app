<?php

namespace App\Http\Controllers\Bank;

use App\Enums\BankRuleAction;
use App\Enums\BankRuleMatch;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BankPresenter;
use App\Http\Requests\Bank\BankRuleRequest;
use App\Models\BankRule;
use App\Models\RecurringExpense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rules the member taught the inbox ("PIX to Fulano is the rent"):
 * listed, edited, written by hand and removed. Private to the member.
 */
class BankRuleController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request, BankPresenter $presenter): Response
    {
        $user = $this->user($request);

        $rules = BankRule::query()
            ->where('user_id', $user->id)
            ->with('recurringExpense:id,title')
            ->orderBy('label')
            ->get();

        return Inertia::render('bank/rules', [
            'rules' => $rules->map(fn (BankRule $rule) => $presenter->rule($rule))->all(),
            'actions' => array_map(fn (BankRuleAction $action) => ['value' => $action->value, 'label' => $action->label()], BankRuleAction::cases()),
            'matchModes' => array_map(fn (BankRuleMatch $mode) => ['value' => $mode->value, 'label' => $mode->label()], BankRuleMatch::cases()),
            'recurringExpenses' => RecurringExpense::query()
                ->visibleTo($user)
                ->orderBy('title')
                ->get(['id', 'title', 'scope'])
                ->map(fn (RecurringExpense $expense) => ['id' => $expense->id, 'title' => $expense->title, 'scope' => $expense->scope->value])
                ->all(),
        ]);
    }

    public function store(BankRuleRequest $request): RedirectResponse
    {
        $data = $request->toData();
        $user = $this->user($request);

        $this->ensureUnique($user->id, $data['pattern'], $data['match_mode']);

        BankRule::create([...$data, 'user_id' => $user->id]);

        $this->toast('Regra criada.');

        return back();
    }

    public function update(BankRuleRequest $request, BankRule $rule): RedirectResponse
    {
        $data = $request->toData();

        $this->ensureUnique($rule->user_id, $data['pattern'], $data['match_mode'], $rule->id);

        $rule->update($data);

        $this->toast('Regra atualizada.');

        return back();
    }

    public function destroy(BankRule $rule): RedirectResponse
    {
        Gate::authorize('delete', $rule);

        $rule->delete();

        $this->toast('Regra removida. As transações já catalogadas não mudam.');

        return back();
    }

    private function ensureUnique(int $userId, string $pattern, BankRuleMatch $mode, ?int $ignoreId = null): void
    {
        $exists = BankRule::query()
            ->where('user_id', $userId)
            ->where('pattern', $pattern)
            ->where('match_mode', $mode)
            ->when($ignoreId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['label' => 'Já existe uma regra com este texto e esta comparação.']);
        }
    }
}
