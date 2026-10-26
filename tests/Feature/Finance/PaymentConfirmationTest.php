<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\PaymentEventType;
use App\Models\AuditLog;
use App\Models\PaymentEvent;
use App\Services\SalaryService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class PaymentConfirmationTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    public function test_an_expense_created_by_leticia_is_confirmed_by_bruno(): void
    {
        Date::setTestNow('2026-10-21 14:30:00');
        $expense = $this->createExpense($this->leticia, 100_000);

        $this->actingAs($this->leticia)
            ->post(route('expenses.payment.store', $expense))
            ->assertForbidden();

        $this->assertFalse($expense->fresh()->isPaid());

        $this->actingAs($this->bruno)
            ->post(route('expenses.payment.store', $expense))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense->refresh();
        $this->assertTrue($expense->isPaid());
        $this->assertSame($this->bruno->id, $expense->paid_by_user_id);
        $this->assertSame('2026-10-21 14:30:00', $expense->paid_at?->format('Y-m-d H:i:s'));

        $event = PaymentEvent::query()->sole();
        $this->assertSame(PaymentEventType::Paid, $event->type);
        $this->assertSame($this->bruno->id, $event->user_id);
        $this->assertSame(100_000, $event->amount_cents);

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.confirmed', 'user_id' => $this->bruno->id, 'auditable_id' => $expense->id]);

        Date::setTestNow();
    }

    public function test_an_expense_created_by_bruno_is_confirmed_by_leticia(): void
    {
        $expense = $this->createExpense($this->bruno, 50_000);

        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense))->assertForbidden();
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $expense))->assertRedirect();

        $this->assertSame($this->leticia->id, $expense->fresh()->paid_by_user_id);
    }

    public function test_the_reciprocal_action_is_labelled_with_the_counterpart(): void
    {
        $byLeticia = $this->createExpense($this->leticia, 100_000);
        $byBruno = $this->createExpense($this->bruno, 100_000);

        $page = $this->actingAs($this->bruno)->get(route('expenses.index'))->assertOk();

        $rows = collect($page->inertiaProps('occurrences.data'))->keyBy('id');

        $this->assertSame('Pago pelo Bruno', $rows[$byLeticia->id]['payment']['confirm_label']);
        $this->assertTrue($rows[$byLeticia->id]['payment']['can_confirm']);
        $this->assertSame('Pago pela Letícia', $rows[$byBruno->id]['payment']['confirm_label']);
        $this->assertFalse($rows[$byBruno->id]['payment']['can_confirm']);
    }

    public function test_an_expense_is_never_considered_paid_because_of_its_creator(): void
    {
        $expense = $this->createExpense($this->bruno, 100_000);

        $this->assertFalse($expense->fresh()->isPaid());
        $this->assertNull($expense->fresh()->paid_at);
    }

    public function test_paying_twice_is_rejected(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);

        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense))->assertRedirect();
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense))->assertForbidden();

        $this->assertSame(1, PaymentEvent::query()->count());
    }

    public function test_paid_expenses_cannot_be_edited_or_deleted(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense));

        $this->actingAs($this->leticia)
            ->put(route('expenses.update', $expense), [
                'title' => 'Alterado', 'amount' => '1.00', 'due_date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->actingAs($this->bruno)->delete(route('expenses.destroy', $expense))->assertForbidden();

        $this->assertSame(100_000, $expense->fresh()->amount_cents);
        $this->assertNotSoftDeleted($expense);
    }

    public function test_only_the_confirming_member_can_reopen_with_a_reason(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense));

        $this->actingAs($this->leticia)
            ->delete(route('expenses.payment.destroy', $expense), ['reason' => 'Engano'])
            ->assertForbidden();

        $this->actingAs($this->bruno)
            ->delete(route('expenses.payment.destroy', $expense), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($expense->fresh()->isPaid());

        $this->actingAs($this->bruno)
            ->delete(route('expenses.payment.destroy', $expense), ['reason' => 'Pagamento estornado pelo banco'])
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertFalse($expense->isPaid());
        $this->assertNull($expense->paid_by_user_id);

        $events = PaymentEvent::query()->orderBy('id')->get();
        $this->assertSame([PaymentEventType::Paid, PaymentEventType::Reopened], $events->pluck('type')->all());
        $this->assertSame('Pagamento estornado pelo banco', $events->last()->reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reopened', 'user_id' => $this->bruno->id]);
    }

    public function test_reopened_expenses_follow_current_salaries_again(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense));

        app(SalaryService::class)->update($this->household, [$this->bruno->id => 500_000, $this->leticia->id => 500_000], $this->bruno);
        $this->assertSame([$this->bruno->id => 80_000, $this->leticia->id => 20_000], $this->sharesOf($expense));

        $this->actingAs($this->bruno)->delete(route('expenses.payment.destroy', $expense), ['reason' => 'Pago em duplicidade']);

        $this->assertSame([$this->bruno->id => 50_000, $this->leticia->id => 50_000], $this->sharesOf($expense));
    }

    public function test_personal_expenses_are_paid_by_their_owner_only(): void
    {
        $expense = $this->createExpense($this->bruno, 9_900, scope: ExpenseScope::Personal);

        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $expense))->assertNotFound();
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $expense))->assertRedirect();
        $this->assertTrue($expense->fresh()->isPaid());

        $this->actingAs($this->bruno)
            ->delete(route('expenses.payment.destroy', $expense), ['reason' => 'Marquei errado'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($expense->fresh()->isPaid());
    }

    public function test_members_of_other_households_cannot_touch_payments(): void
    {
        $outsider = $this->createOutsider();
        $expense = $this->createExpense($this->leticia, 100_000);

        $this->actingAs($outsider)->post(route('expenses.payment.store', $expense))->assertNotFound();
        $this->assertFalse($expense->fresh()->isPaid());
    }

    public function test_pay_all_confirms_every_expense_of_the_month_the_user_can_confirm(): void
    {
        $october = Month::parse('2026-10');
        $byLeticia = $this->createExpense($this->leticia, 100_000, $october);
        $alsoByLeticia = $this->createExpense($this->leticia, 2_550, $october);
        $byBruno = $this->createExpense($this->bruno, 40_000, $october);
        $nextMonth = $this->createExpense($this->leticia, 70_000, Month::parse('2026-11'));
        $personal = $this->createExpense($this->bruno, 9_900, $october, ExpenseScope::Personal);
        $alreadyPaid = $this->createExpense($this->leticia, 1_000, $october);
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $alreadyPaid));

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.payable_count', 2)
                ->where('totals.payable_cents', 102_550)
            );

        $this->actingAs($this->bruno)
            ->post(route('expenses.pay-all', ['month' => '2026-10', 'scope' => 'household']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        foreach ([$byLeticia, $alsoByLeticia] as $expense) {
            $expense->refresh();
            $this->assertTrue($expense->isPaid());
            $this->assertSame($this->bruno->id, $expense->paid_by_user_id);
        }

        // Bruno's own expense still waits for Letícia; other months and
        // personal expenses are untouched.
        $this->assertFalse($byBruno->fresh()->isPaid());
        $this->assertFalse($nextMonth->fresh()->isPaid());
        $this->assertFalse($personal->fresh()->isPaid());

        $this->assertSame(3, PaymentEvent::query()->where('type', PaymentEventType::Paid)->count());
        $this->assertSame(3, AuditLog::query()->where('action', 'payment.confirmed')->count());

        $this->actingAs($this->leticia)
            ->post(route('expenses.pay-all', ['month' => '2026-10', 'scope' => 'household']))
            ->assertRedirect();

        $this->assertTrue($byBruno->fresh()->isPaid());
        $this->assertSame($this->leticia->id, $byBruno->fresh()->paid_by_user_id);
    }

    public function test_pay_all_honors_the_filters_and_the_personal_scope(): void
    {
        $october = Month::parse('2026-10');
        $household = $this->createExpense($this->leticia, 10_000, $october);
        $personal = $this->createExpense($this->bruno, 9_900, $october, ExpenseScope::Personal);
        $leticiasPersonal = $this->createExpense($this->leticia, 5_000, $october, ExpenseScope::Personal);

        $this->actingAs($this->bruno)
            ->post(route('expenses.pay-all', ['month' => '2026-10', 'scope' => 'household', 'source' => 'fixed']))
            ->assertRedirect();

        $this->assertFalse($household->fresh()->isPaid());

        $this->actingAs($this->bruno)
            ->post(route('expenses.pay-all', ['month' => '2026-10', 'scope' => 'personal']))
            ->assertRedirect();

        $this->assertTrue($personal->fresh()->isPaid());
        $this->assertFalse($household->fresh()->isPaid());
        $this->assertFalse($leticiasPersonal->fresh()->isPaid());
    }
}
