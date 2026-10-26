<?php

namespace App\Http\Presenters;

use App\Enums\OccurrenceSource;
use App\Enums\RecurringKind;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shapes expense occurrences for the frontend, including the permissions and
 * labels of the reciprocal payment confirmation.
 */
final class ExpensePresenter
{
    /**
     * Relations needed by {@see occurrence()}.
     *
     * @var list<string>
     */
    public const RELATIONS = [
        'creator:id,name',
        'editor:id,name',
        'payer:id,name',
        'shares',
        'recurringExpense:id,kind',
    ];

    /**
     * @param  Collection<int, HouseholdMember>  $members  Members keyed by user id, with users loaded.
     */
    public function __construct(private readonly User $viewer, private readonly Collection $members)
    {
        //
    }

    public static function for(User $viewer, Household $household): self
    {
        return new self($viewer, $household->members()->with('user:id,name')->get()->keyBy('user_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function occurrence(ExpenseOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'title' => $occurrence->title,
            'notes' => $occurrence->notes,
            'scope' => $occurrence->scope->value,
            'source' => $occurrence->source->value,
            'source_label' => $this->sourceLabel($occurrence),
            'recurring_expense_id' => $occurrence->recurring_expense_id,
            'installment_number' => $occurrence->installment_number,
            'installment_count' => $occurrence->installment_count,
            'billing_month' => $occurrence->billing_month->format('Y-m'),
            'due_date' => $occurrence->due_date->toDateString(),
            'amount_cents' => $occurrence->amount_cents,
            'status' => $occurrence->status->value,
            'is_overdue' => $occurrence->isOverdue(),
            'is_customized' => $occurrence->is_customized,
            'awaits_amount' => $occurrence->awaitsAmount(),
            'shares' => $occurrence->shares
                ->map(fn (ExpenseShare $share) => [
                    'user_id' => $share->user_id,
                    'amount_cents' => $share->amount_cents,
                    'salary_cents' => $share->salary_cents,
                    'combined_salary_cents' => $share->combined_salary_cents,
                ])
                ->values()
                ->all(),
            'created_by' => ['id' => $occurrence->creator->id, 'name' => $occurrence->creator->name],
            'created_at' => $occurrence->created_at?->toIso8601String(),
            'updated_by' => $occurrence->editor ? ['id' => $occurrence->editor->id, 'name' => $occurrence->editor->name] : null,
            'updated_at' => $occurrence->updated_at?->toIso8601String(),
            'paid_at' => $occurrence->paid_at?->toIso8601String(),
            'paid_by' => $occurrence->payer ? ['id' => $occurrence->payer->id, 'name' => $occurrence->payer->name] : null,
            'payment' => [
                'confirm_label' => $this->confirmLabel($occurrence),
                'paid_label' => $this->paidLabel($occurrence),
                'can_confirm' => $this->viewer->can('confirmPayment', $occurrence),
                'can_reopen' => $this->viewer->can('reopenPayment', $occurrence),
            ],
            'can' => [
                'update' => $this->viewer->can('update', $occurrence),
                'delete' => $this->viewer->can('delete', $occurrence),
            ],
        ];
    }

    /**
     * @param  iterable<ExpenseOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    public function occurrences(iterable $occurrences): array
    {
        $rows = [];

        foreach ($occurrences as $occurrence) {
            $rows[] = $this->occurrence($occurrence);
        }

        return $rows;
    }

    /**
     * Members as shown across the application.
     *
     * @return list<array{user_id: int, name: string, first_name: string, salary_cents: int, payment_label: string}>
     */
    public function members(): array
    {
        return array_values($this->members
            ->map(fn (HouseholdMember $member) => [
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'first_name' => $member->user->firstName(),
                'salary_cents' => $member->monthly_salary_cents,
                'payment_label' => $member->paymentLabel(),
            ])
            ->all());
    }

    private function sourceLabel(ExpenseOccurrence $occurrence): string
    {
        return match ($occurrence->source) {
            OccurrenceSource::OneOff => 'Avulsa',
            OccurrenceSource::Recurring => $occurrence->recurringExpense?->kind === RecurringKind::Temporary ? 'Extra temporária' : 'Fixa',
        };
    }

    /**
     * The label of the reciprocal confirmation: the member who is not the
     * creator, e.g. "Pago pelo Bruno" for an expense created by Letícia.
     */
    private function confirmLabel(ExpenseOccurrence $occurrence): string
    {
        if (! $occurrence->isHousehold()) {
            return 'Marcar como pago';
        }

        $others = $this->members->reject(fn (HouseholdMember $member) => $member->user_id === $occurrence->created_by_user_id);

        if ($others->count() === 1) {
            return $others->first()->paymentLabel();
        }

        $viewer = $this->members->get($this->viewer->id);

        if ($viewer !== null && $occurrence->created_by_user_id !== $this->viewer->id) {
            return $viewer->paymentLabel();
        }

        return 'Aguardando confirmação';
    }

    private function paidLabel(ExpenseOccurrence $occurrence): ?string
    {
        if (! $occurrence->isPaid()) {
            return null;
        }

        if (! $occurrence->isHousehold()) {
            return 'Pago';
        }

        $payer = $this->members->get((int) $occurrence->paid_by_user_id);

        return $payer?->paymentLabel() ?? ('Pago por '.($occurrence->payer->name ?? 'membro removido'));
    }
}
