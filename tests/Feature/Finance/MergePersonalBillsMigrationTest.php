<?php

namespace Tests\Feature\Finance;

use App\Enums\BankCatalogType;
use App\Enums\BankRuleAction;
use App\Enums\PaymentEventType;
use App\Enums\RecurringKind;
use App\Enums\TransactionType;
use App\Models\BankRule;
use App\Models\BankSplitItem;
use App\Models\ExpenseOccurrence;
use App\Models\PaymentEvent;
use App\Models\PersonalTransaction;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

/**
 * The personal bills (expense occurrences with the personal scope) become
 * expense entries of their owner.
 */
class MergePersonalBillsMigrationTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_personal_bills_become_expense_entries_of_their_owner(): void
    {
        $household = $this->createExpense($this->leticia, 50_000, Month::parse('2026-11'));
        $gym = $this->personalBill($this->bruno, 9_900, '2026-11', ['notes' => 'Plano anual']);
        $course = $this->personalBill($this->leticia, 30_000, '2026-10', ['due_date' => '2026-11-05']);
        $paid = $this->personalBill($this->bruno, 4_500, '2026-11');
        PaymentEvent::create(['expense_occurrence_id' => $paid->id, 'user_id' => $this->bruno->id, 'type' => PaymentEventType::Paid, 'amount_cents' => 4_500]);
        $noAmount = $this->personalBill($this->bruno, 0, '2026-11');
        $removed = $this->personalBill($this->bruno, 7_000, '2026-11');
        $removed->delete();

        $this->migrate();

        $this->assertModelExists($household);
        foreach ([$gym, $course, $paid, $noAmount, $removed] as $bill) {
            $this->assertNull(ExpenseOccurrence::withTrashed()->find($bill->id));
        }
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(0, DB::table('expense_occurrences')->where('scope', 'personal')->count());

        $entries = PersonalTransaction::query()->orderBy('id')->get();
        $this->assertCount(3, $entries);

        [$gymEntry, $courseEntry, $paidEntry] = $entries;
        $this->assertSame([$this->bruno->id, TransactionType::Expense, 'Academia', 9_900, '2026-11-10', 'Plano anual'], [
            $gymEntry->user_id, $gymEntry->type, $gymEntry->title, $gymEntry->amount_cents, $gymEntry->transaction_date->toDateString(), $gymEntry->notes,
        ]);
        // Due in another month: it stays in the month it was billed.
        $this->assertSame($this->leticia->id, $courseEntry->user_id);
        $this->assertSame('2026-10-01', $courseEntry->transaction_date->toDateString());
        $this->assertSame(4_500, $paidEntry->amount_cents);
    }

    public function test_bank_transactions_and_parts_follow_the_bill(): void
    {
        $bill = $this->personalBill($this->bruno, 12_000, '2026-11');
        $account = $this->createBankAccount($this->createBankConnection($this->bruno));
        $linked = $this->createBankTransaction($account, ['description' => 'Academia', 'amount' => 120]);
        $linked->update(['review_status' => 'catalogued', 'catalog_type' => BankCatalogType::Linked, 'expense_occurrence_id' => $bill->id, 'reviewed_at' => now()]);
        $split = $this->createBankTransaction($account, ['description' => 'Loja', 'amount' => 200]);
        $split->update(['review_status' => 'catalogued', 'catalog_type' => BankCatalogType::Split, 'reviewed_at' => now()]);
        $part = BankSplitItem::create(['bank_transaction_id' => $split->id, 'user_id' => $this->bruno->id, 'type' => BankCatalogType::Linked, 'amount_cents' => 12_000, 'expense_occurrence_id' => $bill->id]);

        $gone = $this->personalBill($this->bruno, 0, '2026-11');
        $orphan = $this->createBankTransaction($account, ['description' => 'Luz', 'amount' => 80]);
        $orphan->update(['review_status' => 'catalogued', 'catalog_type' => BankCatalogType::Linked, 'expense_occurrence_id' => $gone->id, 'reviewed_at' => now()]);

        $this->migrate();

        $entry = PersonalTransaction::query()->sole();

        $linked->refresh();
        $this->assertNull($linked->expense_occurrence_id);
        $this->assertSame($entry->id, $linked->personal_transaction_id);
        $this->assertSame(BankCatalogType::Personal, $linked->catalog_type);
        $this->assertTrue($linked->isCatalogued());

        $part->refresh();
        $this->assertSame(BankCatalogType::Personal, $part->type);
        $this->assertNull($part->expense_occurrence_id);
        $this->assertSame($entry->id, $part->personal_transaction_id);
        $this->assertSame(BankCatalogType::Split, $split->fresh()->catalog_type);

        // Linked to a bill without money: back to the inbox.
        $orphan->refresh();
        $this->assertTrue($orphan->isPending());
        $this->assertNull($orphan->catalog_type);
        $this->assertNull($orphan->expense_occurrence_id);
    }

    public function test_personal_recurring_bills_are_removed_and_their_rules_become_personal_rules(): void
    {
        $internet = $this->template($this->bruno, 'Internet');
        $gym = $this->template($this->bruno, 'Academia');
        DB::table('recurring_expenses')->where('id', $gym->id)->update(['scope' => 'personal', 'owner_user_id' => $this->bruno->id]);
        DB::table('expense_occurrences')->where('recurring_expense_id', $gym->id)->update(['scope' => 'personal', 'owner_user_id' => $this->bruno->id]);
        DB::table('expense_shares')->whereIn('expense_occurrence_id', $gym->occurrences()->select('id'))->delete();
        $months = $gym->occurrences()->count();

        $gymRule = BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'smart fit', 'label' => 'Smart Fit', 'action' => BankRuleAction::Recurring, 'recurring_expense_id' => $gym->id]);
        $internetRule = BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'provedor', 'label' => 'Provedor', 'action' => BankRuleAction::Recurring, 'recurring_expense_id' => $internet->id]);

        $this->migrate();

        $this->assertModelMissing($gym);
        $this->assertModelExists($internet);
        $this->assertSame($months, PersonalTransaction::query()->where('title', 'Academia')->count());
        $this->assertSame(0, PersonalTransaction::query()->where('title', 'Internet')->count());

        $gymRule->refresh();
        $this->assertSame(BankRuleAction::Personal, $gymRule->action);
        $this->assertNull($gymRule->recurring_expense_id);
        $this->assertSame('Academia', $gymRule->title);

        $internetRule->refresh();
        $this->assertSame(BankRuleAction::Recurring, $internetRule->action);
        $this->assertSame($internet->id, $internetRule->recurring_expense_id);
    }

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_11_23_000001_merge_personal_bills_into_personal_entries.php');
        $migration->up();
    }

    /**
     * A bill as the old "personal" scope stored it: an occurrence without
     * shares, owned by the member.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function personalBill(User $owner, int $amountCents, string $month, array $overrides = []): ExpenseOccurrence
    {
        $bill = ExpenseOccurrence::create([
            'household_id' => $this->household->id,
            'source' => 'one_off',
            'title' => match (true) {
                $owner->is($this->leticia) => 'Curso',
                default => 'Academia',
            },
            'billing_month' => $month.'-01',
            'due_date' => $month.'-10',
            'amount_cents' => $amountCents,
            'created_by_user_id' => $owner->id,
            ...$overrides,
        ]);

        DB::table('expense_occurrences')->where('id', $bill->id)->update(['scope' => 'personal', 'owner_user_id' => $owner->id]);

        return $bill;
    }

    private function template(User $actor, string $title): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $actor, [
            'kind' => RecurringKind::Fixed,
            'title' => $title,
            'notes' => null,
            'amount_cents' => 10_000,
            'due_day' => 10,
            'start_month' => Month::parse('2026-10'),
            'end_month' => null,
            'total_months' => null,
        ]);
    }
}
