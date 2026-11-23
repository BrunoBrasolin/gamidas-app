<?php

namespace Tests\Feature\Bank;

use App\Enums\BankRuleAction;
use App\Enums\BankRuleMatch;
use App\Enums\RecurringKind;
use App\Models\BankRule;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class BankRuleTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    private function recurring(): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $this->leticia, [
            'kind' => RecurringKind::Fixed,
            'title' => 'Aluguel',
            'notes' => null,
            'amount_cents' => 180_000,
            'due_day' => 5,
            'start_month' => Month::current(),
            'end_month' => null,
            'total_months' => null,
        ]);
    }

    public function test_rules_are_listed_only_to_their_owner(): void
    {
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano', 'label' => 'Fulano', 'action' => BankRuleAction::Ignore]);

        $this->actingAs($this->bruno)
            ->get(route('bank.rules.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('bank/rules')->has('rules', 1)->where('rules.0.label', 'Fulano'));

        $this->actingAs($this->leticia)
            ->get(route('bank.rules.index'))
            ->assertInertia(fn (Assert $page) => $page->has('rules', 0));
    }

    public function test_a_rule_points_a_counterparty_to_a_recurring_expense(): void
    {
        $rent = $this->recurring();

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), [
                'label' => 'Fulano de Tal!',
                'match_mode' => 'equals',
                'action' => 'recurring',
                'recurring_expense_id' => $rent->id,
                'title' => 'ignorado',
                'auto_apply' => '1',
            ])
            ->assertSessionHasNoErrors();

        $rule = BankRule::query()->sole();
        $this->assertSame('fulano de tal', $rule->pattern);
        $this->assertSame('Fulano de Tal!', $rule->label);
        $this->assertSame(BankRuleAction::Recurring, $rule->action);
        $this->assertSame($rent->id, $rule->recurring_expense_id);
        $this->assertNull($rule->title);
        $this->assertTrue($rule->auto_apply);
    }

    public function test_recurring_expenses_must_be_of_the_members_household(): void
    {
        $outsider = $this->createOutsider();

        $this->actingAs($outsider)
            ->post(route('bank.rules.store'), ['label' => 'Aluguel', 'match_mode' => 'equals', 'action' => 'recurring', 'recurring_expense_id' => $this->recurring()->id])
            ->assertSessionHasErrors('recurring_expense_id');

        $this->assertSame(0, BankRule::query()->count());
    }

    public function test_duplicates_and_empty_patterns_are_refused(): void
    {
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano', 'label' => 'Fulano', 'action' => BankRuleAction::Ignore]);

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'FULANO', 'match_mode' => 'equals', 'action' => 'personal'])
            ->assertSessionHasErrors('label');

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => '!!!', 'match_mode' => 'equals', 'action' => 'personal'])
            ->assertSessionHasErrors('label');

        // The same text with another comparison is a different rule.
        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Fulano', 'match_mode' => 'contains', 'action' => 'personal'])
            ->assertSessionHasNoErrors();

        // Each member has their own rules.
        $this->actingAs($this->leticia)
            ->post(route('bank.rules.store'), ['label' => 'Fulano', 'match_mode' => 'equals', 'action' => 'personal'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_rule_can_hold_an_amount(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Letícia', 'match_mode' => 'equals', 'amount' => '1500.00', 'action' => 'household', 'title' => 'Parcela da casa'])
            ->assertSessionHasNoErrors();

        // Another value, or any value, for the same person is another rule.
        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Leticia', 'match_mode' => 'equals', 'amount' => '120.00', 'action' => 'personal', 'title' => 'Academia'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Leticia', 'match_mode' => 'equals', 'amount' => '', 'action' => 'personal'])
            ->assertSessionHasNoErrors();

        // The same value twice is not.
        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'LETICIA', 'match_mode' => 'equals', 'amount' => '1500', 'action' => 'ignore'])
            ->assertSessionHasErrors('label');

        $this->assertSame([null, 12_000, 150_000], BankRule::query()->orderBy('amount_cents')->pluck('amount_cents')->all());

        $this->actingAs($this->bruno)
            ->get(route('bank.rules.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rules', 3)
                ->where('rules', fn ($rules) => collect($rules)->pluck('amount_cents')->sort()->values()->all() === [null, 12_000, 150_000]));
    }

    public function test_rules_are_edited_and_removed_only_by_their_owner(): void
    {
        $rule = BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano', 'label' => 'Fulano', 'action' => BankRuleAction::Ignore]);

        $this->actingAs($this->leticia)
            ->put(route('bank.rules.update', $rule), ['label' => 'Fulano', 'match_mode' => 'equals', 'action' => 'household'])
            ->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('bank.rules.destroy', $rule))->assertNotFound();

        $this->actingAs($this->bruno)
            ->put(route('bank.rules.update', $rule), ['label' => 'Fulano', 'match_mode' => 'contains', 'action' => 'household', 'title' => 'Aluguel'])
            ->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame(BankRuleMatch::Contains, $rule->match_mode);
        $this->assertSame(BankRuleAction::Household, $rule->action);
        $this->assertSame('Aluguel', $rule->title);

        $this->actingAs($this->bruno)->delete(route('bank.rules.destroy', $rule))->assertSessionHasNoErrors();
        $this->assertModelMissing($rule);
    }
}
