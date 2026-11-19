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
use App\Services\Bank\BankRuleApplier;
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

    public function store(BankRuleRequest $request, BankRuleApplier $applier): RedirectResponse
    {
        $data = $request->toData();
        $user = $this->user($request);

        $this->ensureUnique($user->id, $data['pattern'], $data['match_mode'], $data['amount_cents']);

        $rule = BankRule::create([...$data, 'user_id' => $user->id]);

        $this->toastSaved('Regra criada.', $rule, $request, $applier);

        return back();
    }

    public function update(BankRuleRequest $request, BankRule $rule, BankRuleApplier $applier): RedirectResponse
    {
        $data = $request->toData();

        $this->ensureUnique($rule->user_id, $data['pattern'], $data['match_mode'], $data['amount_cents'], $rule->id);

        $rule->update($data);

        $this->toastSaved('Regra atualizada.', $rule, $request, $applier);

        return back();
    }

    public function destroy(BankRule $rule): RedirectResponse
    {
        Gate::authorize('delete', $rule);

        $rule->delete();

        $this->toast('Regra removida. As transações já catalogadas não mudam.');

        return back();
    }

    /**
     * An automatic rule is applied right away to the pending transactions it
     * is for, and the toast tells how many were looked at and catalogued.
     */
    private function toastSaved(string $message, BankRule $rule, Request $request, BankRuleApplier $applier): void
    {
        if (! $rule->auto_apply) {
            $this->toast($message);

            return;
        }

        $applied = $applier->apply($rule, $this->user($request));

        $this->toast($message, description: 'Aplicada às pendentes: '.$applied->summary());
    }

    private function ensureUnique(int $userId, string $pattern, BankRuleMatch $mode, ?int $amountCents, ?int $ignoreId = null): void
    {
        $exists = BankRule::query()
            ->where('user_id', $userId)
            ->where('pattern', $pattern)
            ->where('match_mode', $mode)
            ->where('amount_cents', $amountCents)
            ->when($ignoreId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['label' => 'Já existe uma regra com este texto, esta comparação e este valor.']);
        }
    }
}
