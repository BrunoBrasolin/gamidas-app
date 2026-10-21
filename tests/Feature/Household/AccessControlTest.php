<?php

namespace Tests\Feature\Household;

use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Models\User;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    public function test_each_household_member_can_log_in(): void
    {
        foreach (['bruno@example.test', 'leticia@example.test'] as $email) {
            $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));

            $this->assertAuthenticatedAs(User::query()->where('email', $email)->sole());

            $this->post(route('logout'));
            $this->assertGuest();
        }
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Intruso', 'email' => 'x@example.test', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard' => ['/dashboard'];
        yield 'expenses' => ['/expenses'];
        yield 'recurring expenses' => ['/recurring-expenses'];
        yield 'personal summary' => ['/personal'];
        yield 'personal income' => ['/personal/income'];
        yield 'personal expenses' => ['/personal/expenses'];
        yield 'personal investments' => ['/personal/investments'];
        yield 'reports' => ['/reports'];
        yield 'household members' => ['/household/members'];
        yield 'household salaries' => ['/household/salaries'];
    }

    #[DataProvider('pages')]
    public function test_guests_are_redirected_to_login(string $url): void
    {
        $this->get($url)->assertRedirect(route('login'));
    }

    #[DataProvider('pages')]
    public function test_members_can_open_every_page(string $url): void
    {
        $this->actingAs($this->leticia)->get($url)->assertOk();
    }

    public function test_users_without_a_household_are_refused(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get('/dashboard')->assertForbidden();
        $this->actingAs($stranger)->post('/expenses', [])->assertForbidden();
    }

    public function test_writes_are_rejected_for_guests(): void
    {
        $expense = $this->createExpense($this->leticia, 10_000);

        $this->post(route('expenses.payment.store', $expense))->assertRedirect(route('login'));
        $this->put(route('household.salaries.update'), [])->assertRedirect(route('login'));
        $this->assertFalse($expense->fresh()->isPaid());
    }

    public function test_records_of_another_household_are_not_found(): void
    {
        $outsider = $this->createOutsider();
        $expense = $this->createExpense($this->bruno, 10_000);
        $template = app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'scope' => ExpenseScope::Household, 'kind' => RecurringKind::Fixed, 'title' => 'Internet', 'notes' => null,
            'amount_cents' => 10_000, 'due_day' => 10,
            'start_month' => Month::current(), 'end_month' => null, 'total_months' => null,
        ]);
        $member = $this->bruno->membership;

        $this->actingAs($outsider);

        $this->put(route('expenses.update', $expense), ['title' => 'x'])->assertNotFound();
        $this->delete(route('expenses.destroy', $expense))->assertNotFound();
        $this->put(route('recurring-expenses.update', $template), ['title' => 'x'])->assertNotFound();
        $this->post(route('recurring-expenses.end', $template), ['end_month' => '2030-01'])->assertNotFound();
        $this->delete(route('recurring-expenses.destroy', $template))->assertNotFound();
        $this->put(route('household.members.update', $member), ['payment_label_preposition' => 'por'])->assertNotFound();

        $this->assertModelExists($template);
    }

    public function test_listings_never_include_another_households_data(): void
    {
        $outsider = $this->createOutsider();
        $this->createExpense($this->bruno, 12_345);

        $page = $this->actingAs($outsider)->get(route('expenses.index'))->assertOk();

        $this->assertSame([], $page->inertiaProps('occurrences.data'));
        $this->assertSame(0, $page->inertiaProps('totals.total_cents'));
    }

    public function test_household_members_cannot_delete_their_accounts(): void
    {
        $this->actingAs($this->bruno)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertModelExists($this->bruno);
    }
}
