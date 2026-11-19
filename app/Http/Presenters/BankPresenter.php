<?php

namespace App\Http\Presenters;

use App\Enums\BankRuleAction;
use App\Models\BankAccount;
use App\Models\BankBill;
use App\Models\BankConnection;
use App\Models\BankInvestment;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Services\Bank\BankConnectionService;
use App\Services\Bank\BankSuggestion;
use Carbon\CarbonImmutable;

/**
 * Shapes bank connections, imported transactions and rules for the pages.
 */
final class BankPresenter
{
    /**
     * Human labels of Pluggy item statuses.
     */
    private const STATUS_LABELS = [
        'UPDATED' => 'Atualizada',
        'UPDATING' => 'Atualizando',
        'LOGIN_ERROR' => 'Erro de autorização',
        'OUTDATED' => 'Desatualizada',
        'WAITING_USER_INPUT' => 'Aguardando você',
        'DELETED' => 'Removida na Pluggy',
    ];

    public function __construct(private readonly BankConnectionService $connections)
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function connection(BankConnection $connection): array
    {
        $investments = $connection->investments->sortByDesc('balance_cents')->values();

        return [
            'id' => $connection->id,
            'connector_name' => $connection->connector_name,
            'connector_image_url' => $connection->connector_image_url,
            'connector_color' => $connection->connector_color,
            'status' => $connection->status,
            'status_label' => self::STATUS_LABELS[$connection->status] ?? $connection->status,
            'needs_reconnect' => $connection->needsReconnect() || $connection->status === 'DELETED',
            'error_message' => $connection->error_message,
            'consent_expires_at' => $connection->consent_expires_at?->toIso8601String(),
            'consent_expiring' => $connection->consentExpiringSoon(),
            'is_meu_pluggy' => $this->connections->isMeuPluggy($connection),
            'import_from' => $connection->import_from->toDateString(),
            'bank_updated_at' => $connection->bank_updated_at?->toIso8601String(),
            'synced_at' => $connection->synced_at?->toIso8601String(),
            'sync_error' => $connection->sync_error,
            'accounts' => $connection->accounts->sortBy(fn (BankAccount $account) => [$account->type->value, $account->name])->values()->map(fn (BankAccount $account) => $this->account($account))->all(),
            'investments' => $investments->map(fn (BankInvestment $investment) => [
                'id' => $investment->id,
                'name' => $investment->name,
                'type' => $investment->type,
                'subtype' => $investment->subtype,
                'balance_cents' => $investment->balance_cents,
                'invested_cents' => $investment->invested_cents,
                'rate' => $investment->rate,
                'due_date' => $investment->due_date?->toDateString(),
            ])->all(),
            'investments_total_cents' => (int) $investments->sum('balance_cents'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function account(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'number' => $account->number,
            'balance_cents' => $account->balance_cents,
            'credit_limit_cents' => $account->credit_limit_cents,
            'available_credit_cents' => $account->available_credit_cents,
            'balance_due_date' => $account->balance_due_date?->toDateString(),
            'bills' => $account->relationLoaded('bills')
                ? $account->bills->sortByDesc('due_date')->take(4)->values()->map(fn (BankBill $bill) => [
                    'id' => $bill->id,
                    'due_date' => $bill->due_date->toDateString(),
                    'total_cents' => $bill->total_cents,
                    'minimum_payment_cents' => $bill->minimum_payment_cents,
                    'finance_charges_cents' => $bill->finance_charges_cents,
                ])->all()
                : [],
        ];
    }

    /**
     * @param  list<array{occurrence: ExpenseOccurrence, score: int, strong: bool}>  $candidates
     * @return array<string, mixed>
     */
    public function transaction(BankTransaction $transaction, CarbonImmutable $referenceDate, ?BankSuggestion $suggestion = null, array $candidates = []): array
    {
        $occurrence = $transaction->expenseOccurrence;
        $entry = $transaction->personalTransaction;

        return [
            'id' => $transaction->id,
            'date' => $transaction->date->toDateString(),
            'reference_date' => $referenceDate->toDateString(),
            'description' => $transaction->description,
            'display_name' => $transaction->displayName(),
            'counterparty_name' => $transaction->counterparty_name,
            'counterparty_document' => $transaction->counterparty_document,
            'amount_cents' => $transaction->amount_cents,
            'direction' => $transaction->direction->value,
            'channel' => $transaction->channel->value,
            'channel_label' => $transaction->channel->label(),
            'pending_at_bank' => $transaction->bank_status === 'PENDING',
            'installment_number' => $transaction->installment_number,
            'installment_count' => $transaction->installment_count,
            'account' => [
                'id' => $transaction->account->id,
                'name' => $transaction->account->name,
                'type' => $transaction->account->type->value,
            ],
            'review_status' => $transaction->review_status->value,
            'catalog_type' => $transaction->catalog_type?->value,
            'catalog_label' => $transaction->catalog_type?->label(),
            'ignore_reason' => $transaction->ignore_reason,
            'removed' => $transaction->removed_at !== null,
            'expense' => $transaction->expense_occurrence_id === null ? null : ($occurrence === null
                ? ['missing' => true]
                : [
                    'missing' => false,
                    'id' => $occurrence->id,
                    'title' => $occurrence->title,
                    'scope' => $occurrence->scope->value,
                    'billing_month' => $occurrence->billing_month->format('Y-m'),
                    'status' => $occurrence->status->value,
                ]),
            'entry' => $transaction->personal_transaction_id === null && ! in_array($transaction->catalog_type?->value, ['personal', 'income', 'investment'], true)
                ? null
                : ($entry === null
                    ? ['missing' => true]
                    : [
                        'missing' => false,
                        'id' => $entry->id,
                        'type' => $entry->type->value,
                        'slug' => $entry->type->slug(),
                        'title' => $entry->title,
                        'month' => $entry->transaction_date->format('Y-m'),
                    ]),
            'suggestion' => $suggestion === null ? null : [
                'action' => $suggestion->choice->action,
                'label' => $suggestion->label,
                'title' => $suggestion->choice->title,
                'occurrence_id' => $suggestion->choice->occurrenceId,
                'rule' => $suggestion->rule === null ? null : ['id' => $suggestion->rule->id, 'label' => $suggestion->rule->label],
            ],
            'candidates' => array_map(fn (array $candidate) => $this->candidate($candidate['occurrence']) + [
                'strong' => $candidate['strong'],
            ], $candidates),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rule(BankRule $rule): array
    {
        return [
            'id' => $rule->id,
            'label' => $rule->label,
            'pattern' => $rule->pattern,
            'match_mode' => $rule->match_mode->value,
            'match_label' => $rule->match_mode->label(),
            'action' => $rule->action->value,
            'action_label' => $rule->action->label(),
            'title' => $rule->title,
            'recurring_expense' => $rule->action === BankRuleAction::Recurring && $rule->recurringExpense !== null
                ? ['id' => $rule->recurringExpense->id, 'title' => $rule->recurringExpense->title]
                : null,
            'auto_apply' => $rule->auto_apply,
            'hits' => $rule->hits,
            'last_used_at' => $rule->last_used_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(ExpenseOccurrence $occurrence): array
    {
        return [
            'id' => $occurrence->id,
            'title' => $occurrence->title,
            'scope' => $occurrence->scope->value,
            'amount_cents' => $occurrence->amount_cents,
            'due_date' => $occurrence->due_date->toDateString(),
            'billing_month' => $occurrence->billing_month->format('Y-m'),
            'installment_number' => $occurrence->installment_number,
            'installment_count' => $occurrence->installment_count,
            'awaits_amount' => $occurrence->awaitsAmount(),
            'is_recurring' => $occurrence->recurring_expense_id !== null,
        ];
    }
}
