<?php

namespace App\Policies;

use App\Models\BankSplitItem;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpenseOccurrencePolicy
{
    /**
     * Household expenses are visible to every member of the household.
     */
    public function view(User $user, ExpenseOccurrence $occurrence): bool
    {
        return $occurrence->household_id === $user->householdId();
    }

    public function update(User $user, ExpenseOccurrence $occurrence): Response
    {
        if (! $this->view($user, $occurrence)) {
            return Response::denyAsNotFound();
        }

        return $occurrence->isPaid()
            ? Response::deny('Despesas pagas não podem ser alteradas. Reabra o pagamento primeiro.')
            : Response::allow();
    }

    public function delete(User $user, ExpenseOccurrence $occurrence): Response
    {
        return $this->update($user, $occurrence);
    }

    /**
     * Any member can take a pending household expense into their own personal
     * finances (it becomes one of their expense entries and leaves the
     * split). Never into someone else's: personal records are only written by
     * their owner. An expense backed by another member's bank transaction
     * stays with them, so their inbox never points at someone else's private
     * entry. It needs an amount: an entry cannot be empty.
     */
    public function moveToPersonal(User $user, ExpenseOccurrence $occurrence): Response
    {
        if (! $this->view($user, $occurrence)) {
            return Response::denyAsNotFound();
        }

        if ($occurrence->isPaid()) {
            return Response::deny('Despesas pagas não podem ser movidas. Reabra o pagamento primeiro.');
        }

        if ($occurrence->awaitsAmount()) {
            return Response::deny('Informe o valor deste mês antes de movê-la para as suas finanças pessoais.');
        }

        $transactions = $occurrence->relationLoaded('bankTransactions')
            ? $occurrence->bankTransactions
            : $occurrence->bankTransactions()->get(['id', 'expense_occurrence_id', 'user_id']);
        $parts = $occurrence->relationLoaded('bankSplitItems')
            ? $occurrence->bankSplitItems
            : $occurrence->bankSplitItems()->get(['id', 'expense_occurrence_id', 'user_id']);

        // As a whole or as a part of a split transaction.
        $foreign = $transactions->first(fn (BankTransaction $transaction) => $transaction->user_id !== $user->id)->user_id
            ?? $parts->first(fn (BankSplitItem $part) => $part->user_id !== $user->id)?->user_id;

        if ($foreign !== null) {
            $owner = User::query()->find($foreign)?->firstName() ?? 'outro membro';

            return Response::deny("Esta despesa está vinculada ao extrato de {$owner}; só {$owner} pode movê-la para as próprias finanças pessoais.");
        }

        return Response::allow();
    }

    /**
     * A household expense must be confirmed as paid by a member other than
     * its creator ("Pago pelo Bruno" for an expense created by Letícia, and
     * vice versa).
     */
    public function confirmPayment(User $user, ExpenseOccurrence $occurrence): Response
    {
        if (! $this->view($user, $occurrence)) {
            return Response::denyAsNotFound();
        }

        if ($occurrence->isPaid()) {
            return Response::deny('Esta despesa já foi marcada como paga.');
        }

        if ($occurrence->awaitsAmount()) {
            return Response::deny('Informe o valor deste mês antes de confirmar o pagamento.');
        }

        if ($occurrence->created_by_user_id === $user->id) {
            return Response::deny('O pagamento de uma despesa da casa deve ser confirmado pelo outro membro da residência.');
        }

        return Response::allow();
    }

    /**
     * The house settlement ("Acerto da casa"): a transfer between the two
     * members, seen in the bank of either one, pays the share each owes on
     * the chosen household expenses. Unlike the reciprocal confirmation, the
     * creator may settle their own expense: the proof is the other member's
     * money arriving in their bank, not their word. The payment is recorded
     * in the name of the member who owed the share.
     */
    public function settle(User $user, ExpenseOccurrence $occurrence): Response
    {
        if (! $this->view($user, $occurrence)) {
            return Response::denyAsNotFound();
        }

        if ($occurrence->isPaid()) {
            return Response::deny("“{$occurrence->title}” já foi paga.");
        }

        if ($occurrence->awaitsAmount()) {
            return Response::deny("Informe o valor de “{$occurrence->title}” antes do acerto.");
        }

        return Response::allow();
    }

    /**
     * Only the member who confirmed a household payment can reopen it. An
     * expense paid by a
     * house settlement is reopened by undoing the settlement in the inbox.
     */
    public function reopenPayment(User $user, ExpenseOccurrence $occurrence): Response
    {
        if (! $this->view($user, $occurrence)) {
            return Response::denyAsNotFound();
        }

        if (! $occurrence->isPaid()) {
            return Response::deny('Esta despesa não está paga.');
        }

        if ($occurrence->isSettled()) {
            return Response::deny('Esta despesa foi paga num acerto da casa. Para reabri-la, desfaça o acerto na caixa de entrada do banco.');
        }

        if ($occurrence->paid_by_user_id !== $user->id) {
            return Response::deny('Somente quem confirmou o pagamento pode reabri-lo.');
        }

        return Response::allow();
    }
}
