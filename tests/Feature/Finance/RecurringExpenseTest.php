<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\RecurringKind;
use App\Models\ExpenseOccurrence;
use App\Models\RecurringExpense;
use App\Services\ExpenseService;
use App\Services\OccurrenceGenerator;
use App\Services\PaymentService;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        config(['finance.generation_months_ahead' => 2]);

        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function createTemplate(array $overrides = []): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => 'Internet',
            'notes' => null,
            'amount_cents' => 12_000,
            'due_day' => 10,
            'start_month' => Month::parse('2026-09'),
            'end_month' => null,
            'total_months' => null,
            ...$overrides,
        ]);
    }

    /**
     * @return list<string>
     */
    private function months(RecurringExpense $template): array
    {
        return $template->occurrences()->orderBy('billing_month')->get()
            ->map(fn (ExpenseOccurrence $occurrence) => Month::key($occurrence->billing_month))
            ->all();
    }

    public function test_fixed_expenses_generate_one_occurrence_per_month_up_to_the_horizon(): void
    {
        $template = $this->createTemplate();

        $this->assertSame(['2026-09', '2026-10', '2026-11', '2026-12'], $this->months($template));

        $occurrence = $template->occurrences()->where('billing_month', '2026-11-01')->sole();
        $this->assertSame(12_000, $occurrence->amount_cents);
        $this->assertSame('2026-11-10', $occurrence->due_date->toDateString());
        $this->assertSame(OccurrenceSource::Recurring, $occurrence->source);
        $this->assertSame($this->bruno->id, $occurrence->created_by_user_id);
        $this->assertSame([$this->bruno->id => 9_600, $this->leticia->id => 2_400], $this->sharesOf($occurrence));
    }

    public function test_each_month_has_an_independent_payment_status(): void
    {
        $template = $this->createTemplate();
        $october = $template->occurrences()->where('billing_month', '2026-10-01')->sole();

        app(PaymentService::class)->confirm($october, $this->leticia);

        $this->assertSame(1, $template->occurrences()->where('status', 'paid')->count());
        $this->assertSame(3, $template->occurrences()->where('status', 'pending')->count());
    }

    public function test_generation_is_idempotent_and_extends_with_time(): void
    {
        $template = $this->createTemplate();
        $generator = app(OccurrenceGenerator::class);

        $this->assertSame(0, $generator->generate($template));
        $this->assertSame(0, $generator->generateAll());
        $this->assertCount(4, $this->months($template));

        Date::setTestNow('2027-01-05 08:00:00');
        $this->artisan('expenses:generate')->assertSuccessful();
        $this->artisan('expenses:generate')->assertSuccessful();

        $this->assertSame(['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], $this->months($template));
    }

    public function test_duplicate_monthly_occurrences_are_rejected_by_the_database(): void
    {
        $template = $this->createTemplate();

        $this->expectException(UniqueConstraintViolationException::class);

        ExpenseOccurrence::create([
            'household_id' => $this->household->id,
            'scope' => ExpenseScope::Household,
            'source' => OccurrenceSource::Recurring,
            'recurring_expense_id' => $template->id,
            'title' => 'Internet',
            'billing_month' => Month::parse('2026-10'),
            'due_date' => CarbonImmutable::parse('2026-10-10'),
            'amount_cents' => 12_000,
            'created_by_user_id' => $this->bruno->id,
        ]);
    }

    public function test_an_optional_end_month_stops_generation(): void
    {
        $template = $this->createTemplate(['end_month' => Month::parse('2026-11')]);

        app(OccurrenceGenerator::class)->generate($template, Month::parse('2027-06'));

        $this->assertSame(['2026-09', '2026-10', '2026-11'], $this->months($template));
    }

    public function test_editing_a_template_only_changes_pending_months_from_the_effective_month(): void
    {
        $template = $this->createTemplate();
        $september = $template->occurrences()->where('billing_month', '2026-09-01')->sole();
        $october = $template->occurrences()->where('billing_month', '2026-10-01')->sole();
        app(PaymentService::class)->confirm($september, $this->leticia);

        app(RecurringExpenseService::class)->update($template, $this->leticia, [
            'title' => 'Internet fibra',
            'notes' => null,
            'amount_cents' => 15_000,
            'due_day' => 15,
            'end_month' => null,
            'total_months' => null,
        ], Month::parse('2026-11'));

        $this->assertSame(12_000, $september->fresh()->amount_cents);
        $this->assertSame('Internet', $september->fresh()->title);
        $this->assertSame(12_000, $october->fresh()->amount_cents);
        $this->assertSame('2026-10-10', $october->fresh()->due_date->toDateString());

        $november = $template->occurrences()->where('billing_month', '2026-11-01')->sole();
        $this->assertSame(15_000, $november->amount_cents);
        $this->assertSame('Internet fibra', $november->title);
        $this->assertSame('2026-11-15', $november->due_date->toDateString());
        $this->assertSame([$this->bruno->id => 12_000, $this->leticia->id => 3_000], $this->sharesOf($november));
        $this->assertSame($this->leticia->id, $november->updated_by_user_id);
    }

    public function test_a_recurring_month_must_stay_due_within_its_month(): void
    {
        $template = $this->createTemplate();
        $november = $template->occurrences()->where('billing_month', '2026-11-01')->sole();

        $this->actingAs($this->bruno)
            ->put(route('expenses.update', $november), [
                'title' => 'Internet',
                'amount' => '100.00',
                'due_date' => '2026-12-02',
            ])
            ->assertSessionHasErrors('due_date');

        $this->assertSame('2026-11-10', $november->fresh()->due_date->toDateString());
    }

    public function test_template_edits_do_not_override_individually_edited_months(): void
    {
        $template = $this->createTemplate();
        $november = $template->occurrences()->where('billing_month', '2026-11-01')->sole();

        app(ExpenseService::class)->update($november, $this->bruno, [
            'title' => 'Internet (mês com desconto)',
            'notes' => null,
            'amount_cents' => 6_000,
            'due_date' => CarbonImmutable::parse('2026-11-12'),
        ]);

        $november->refresh();
        $this->assertTrue($november->is_customized);
        $this->assertSame('2026-11', Month::key($november->billing_month), 'Recurring months stay in their slot.');

        app(RecurringExpenseService::class)->update($template, $this->bruno, [
            'title' => 'Internet',
            'notes' => null,
            'amount_cents' => 20_000,
            'due_day' => 10,
            'end_month' => null,
            'total_months' => null,
        ], Month::parse('2026-10'));

        $this->assertSame(6_000, $november->fresh()->amount_cents);
        $this->assertSame(20_000, $template->occurrences()->where('billing_month', '2026-12-01')->sole()->amount_cents);
    }

    public function test_ending_a_template_removes_future_pending_months_and_keeps_paid_history(): void
    {
        $template = $this->createTemplate();
        app(PaymentService::class)->confirm($template->occurrences()->where('billing_month', '2026-09-01')->sole(), $this->leticia);

        app(RecurringExpenseService::class)->end($template, $this->bruno, Month::parse('2026-10'));

        $this->assertSame(['2026-09', '2026-10'], $this->months($template));
        $this->assertSame('2026-10', Month::key($template->fresh()->end_month));
        $this->assertTrue($template->fresh()->isActive(), 'Still active during its last month.');

        Date::setTestNow('2027-03-01');
        $this->assertFalse($template->fresh()->isActive());
        app(OccurrenceGenerator::class)->generateAll();
        $this->assertSame(['2026-09', '2026-10'], $this->months($template));
    }

    public function test_a_template_cannot_be_shortened_past_a_paid_month(): void
    {
        $template = $this->createTemplate();
        app(PaymentService::class)->confirm($template->occurrences()->where('billing_month', '2026-12-01')->sole(), $this->leticia);

        $this->expectException(ValidationException::class);

        app(RecurringExpenseService::class)->end($template, $this->bruno, Month::parse('2026-10'));
    }

    public function test_a_removed_month_is_not_generated_again(): void
    {
        $template = $this->createTemplate();
        $november = $template->occurrences()->where('billing_month', '2026-11-01')->sole();

        app(ExpenseService::class)->delete($november, $this->bruno);
        app(OccurrenceGenerator::class)->generate($template, Month::parse('2027-02'));

        $this->assertSame(['2026-09', '2026-10', '2026-12', '2027-01', '2027-02'], $this->months($template));
        $this->assertSoftDeleted($november);
    }

    public function test_temporary_extras_generate_exactly_the_configured_months(): void
    {
        $template = $this->createTemplate([
            'kind' => RecurringKind::Temporary,
            'title' => 'Sofá novo',
            'amount_cents' => 30_000,
            'start_month' => Month::parse('2026-11'),
            'total_months' => 4,
        ]);

        $this->assertSame('2027-02', Month::key($template->end_month));
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], $this->months($template));
        $this->assertSame([30_000], $template->occurrences()->pluck('amount_cents')->unique()->values()->all());

        Date::setTestNow('2027-06-01');
        app(OccurrenceGenerator::class)->generateAll(Month::parse('2028-01'));

        $this->assertCount(4, $this->months($template), 'Generation stops after the configured number of months.');
    }

    public function test_changing_the_duration_of_a_temporary_extra(): void
    {
        $template = $this->createTemplate([
            'kind' => RecurringKind::Temporary,
            'amount_cents' => 30_000,
            'start_month' => Month::parse('2026-10'),
            'total_months' => 4,
        ]);

        $data = [
            'title' => 'Sofá novo', 'notes' => null,
            'amount_cents' => 30_000, 'due_day' => 10, 'end_month' => null,
        ];

        app(RecurringExpenseService::class)->update($template, $this->bruno, [...$data, 'total_months' => 2], Month::parse('2026-10'));
        $this->assertSame(['2026-10', '2026-11'], $this->months($template));

        app(RecurringExpenseService::class)->update($template, $this->bruno, [...$data, 'total_months' => 5], Month::parse('2026-10'));
        $this->assertSame(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02'], $this->months($template));
    }

    public function test_templates_with_paid_months_cannot_be_deleted(): void
    {
        $template = $this->createTemplate();
        app(PaymentService::class)->confirm($template->occurrences()->where('billing_month', '2026-09-01')->sole(), $this->leticia);

        try {
            app(RecurringExpenseService::class)->delete($template, $this->bruno);
            $this->fail('Deleting a template with paid months must fail.');
        } catch (ValidationException) {
            $this->assertModelExists($template);
        }

        $other = $this->createTemplate(['title' => 'Streaming']);
        app(RecurringExpenseService::class)->delete($other, $this->bruno);

        $this->assertModelMissing($other);
        $this->assertSame(0, ExpenseOccurrence::withTrashed()->where('recurring_expense_id', $other->id)->count());
    }

    public function test_templates_are_never_counted_in_monthly_totals(): void
    {
        $this->createTemplate();
        $this->createExpense($this->leticia, 50_000, Month::parse('2026-10'));

        $total = ExpenseOccurrence::query()->household($this->household->id)->forMonth(Month::parse('2026-10'))->sum('amount_cents');

        $this->assertSame(62_000, (int) $total);
    }

    public function test_recurring_expenses_are_managed_through_http(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('recurring-expenses.store'), [
                'kind' => 'temporary',
                'scope' => 'household',
                'title' => 'Geladeira',
                'amount' => '300.00',
                'due_day' => 31,
                'start_month' => '2027-01',
                'total_months' => 4,
            ])
            ->assertSessionHasNoErrors();

        $template = RecurringExpense::query()->where('title', 'Geladeira')->sole();
        $this->assertSame(['2027-01', '2027-02', '2027-03', '2027-04'], $this->months($template));
        $this->assertSame('2027-02-28', $template->occurrences()->where('billing_month', '2027-02-01')->sole()->due_date->toDateString());

        $this->actingAs($this->bruno)
            ->post(route('recurring-expenses.end', $template), ['end_month' => '2027-02'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['2027-01', '2027-02'], $this->months($template));
    }

    public function test_variable_templates_generate_months_waiting_for_their_amount(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('recurring-expenses.store'), [
                'kind' => 'fixed',
                'scope' => 'household',
                'title' => 'Luz',
                'is_variable' => '1',
                'due_day' => 15,
                'start_month' => '2026-10',
            ])
            ->assertSessionHasNoErrors();

        $template = RecurringExpense::query()->where('title', 'Luz')->sole();
        $this->assertTrue($template->is_variable);
        $this->assertSame(0, $template->amount_cents);
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $this->months($template));

        $october = $template->occurrences()->where('billing_month', '2026-10-01')->sole();
        $this->assertSame(0, $october->amount_cents);
        $this->assertTrue($october->awaitsAmount());
        $this->assertSame([$this->bruno->id => 0, $this->leticia->id => 0], $this->sharesOf($october));

        // It shows up as a reminder and cannot be paid without an amount.
        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10', 'status' => 'awaiting_amount']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('occurrences.data', 1)
                ->where('occurrences.data.0.awaits_amount', true)
                ->where('occurrences.data.0.payment.can_confirm', false)
                ->where('summary.awaiting_amount_count', 1)
                ->where('totals.awaiting_amount_count', 1)
            );

        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $october))->assertForbidden();

        // Filling in the month's amount splits it and allows the payment.
        $this->actingAs($this->leticia)
            ->put(route('expenses.update', $october), [
                'title' => 'Luz',
                'amount' => '287.43',
                'due_date' => '2026-10-15',
            ])
            ->assertSessionHasNoErrors();

        $october->refresh();
        $this->assertSame(28_743, $october->amount_cents);
        $this->assertFalse($october->awaitsAmount());
        $this->assertTrue($october->is_customized);
        $this->assertSame(28_743, array_sum($this->sharesOf($october)));

        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $october))->assertRedirect();
        $this->assertTrue($october->fresh()->isPaid());

        // Other months still wait for theirs.
        $this->assertTrue($template->occurrences()->where('billing_month', '2026-11-01')->sole()->awaitsAmount());
    }

    public function test_a_fixed_amount_is_required_unless_the_template_is_variable(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('recurring-expenses.store'), [
                'kind' => 'fixed',
                'scope' => 'household',
                'title' => 'Internet',
                'is_variable' => '0',
                'due_day' => 10,
                'start_month' => '2026-10',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, RecurringExpense::query()->count());
    }

    public function test_a_template_can_become_variable_from_an_effective_month(): void
    {
        $template = $this->createTemplate();

        $this->actingAs($this->bruno)
            ->put(route('recurring-expenses.update', $template), [
                'title' => 'Internet',
                'is_variable' => '1',
                'amount' => '999.99',
                'due_day' => 10,
                'effective_month' => '2026-11',
            ])
            ->assertSessionHasNoErrors();

        $template->refresh();
        $this->assertTrue($template->is_variable);
        $this->assertSame(0, $template->amount_cents);

        $amounts = $template->occurrences()->orderBy('billing_month')->pluck('amount_cents')->map(fn ($cents) => (int) $cents)->all();
        $this->assertSame([12_000, 12_000, 0, 0], $amounts);

        $this->actingAs($this->bruno)
            ->put(route('recurring-expenses.update', $template), [
                'title' => 'Internet',
                'is_variable' => '0',
                'amount' => '130.00',
                'due_day' => 10,
                'effective_month' => '2026-12',
            ])
            ->assertSessionHasNoErrors();

        $amounts = $template->occurrences()->orderBy('billing_month')->pluck('amount_cents')->map(fn ($cents) => (int) $cents)->all();
        $this->assertSame([12_000, 12_000, 0, 13_000], $amounts);
    }
}
