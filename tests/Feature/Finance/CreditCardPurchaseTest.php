<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\PurchaseMode;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\ExpenseOccurrence;
use App\Services\CreditCardPurchaseService;
use App\Services\HouseholdSummary;
use App\Services\PaymentService;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class CreditCardPurchaseTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private CreditCard $card;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();

        $this->card = CreditCard::create([
            'household_id' => $this->household->id,
            'name' => 'Nubank',
            'due_day' => 15,
            'created_by_user_id' => $this->bruno->id,
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function purchase(int $amount = 120_000, int $count = 6, array $overrides = []): CreditCardPurchase
    {
        return app(CreditCardPurchaseService::class)->create($this->household, $this->leticia, [
            ...$this->purchaseData($amount, $count),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseData(int $amount = 120_000, int $count = 6): array
    {
        return [
            'credit_card_id' => $this->card->id,
            'scope' => ExpenseScope::Household,
            'expense_category_id' => null,
            'description' => 'Notebook',
            'notes' => 'Loja X',
            'purchase_date' => CarbonImmutable::parse('2026-10-05'),
            'mode' => PurchaseMode::Installments,
            'amount_cents' => $amount,
            'installment_count' => $count,
            'first_billing_month' => Month::parse('2026-11'),
        ];
    }

    public function test_a_purchase_generates_one_tracked_occurrence_per_installment(): void
    {
        $purchase = $this->purchase();

        $installments = $purchase->installments()->get();

        $this->assertCount(6, $installments);
        $this->assertSame(120_000, $purchase->total_amount_cents);
        $this->assertSame([20_000], $installments->pluck('amount_cents')->unique()->values()->all());
        $this->assertSame([1, 2, 3, 4, 5, 6], $installments->pluck('installment_number')->all());
        $this->assertSame('2026-11', Month::key($installments->first()->billing_month));
        $this->assertSame('2027-04', Month::key($installments->last()->billing_month));
        $this->assertSame('2027-04', Month::key($purchase->lastBillingMonth()));
        $this->assertSame('2026-11-15', $installments->first()->due_date->toDateString());
        $this->assertSame([$this->bruno->id => 16_000, $this->leticia->id => 4_000], $this->sharesOf($installments->first()));
    }

    public function test_the_final_installment_accounts_for_rounding(): void
    {
        $purchase = $this->purchase(100_000, 3);

        $this->assertSame([33_333, 33_333, 33_334], $purchase->installments()->pluck('amount_cents')->all());
        $this->assertSame(100_000, (int) $purchase->installments()->sum('amount_cents'));
    }

    public function test_fixed_monthly_card_charges(): void
    {
        $purchase = $this->purchase(4_990, 12, ['mode' => PurchaseMode::FixedMonthly, 'description' => 'Assinatura']);

        $this->assertSame(59_880, $purchase->total_amount_cents);
        $this->assertSame([4_990], $purchase->installments()->pluck('amount_cents')->unique()->values()->all());
        $this->assertSame(12, $purchase->installments()->count());
    }

    public function test_installments_have_independent_payment_status(): void
    {
        $purchase = $this->purchase();
        $first = $purchase->installments()->first();

        app(PaymentService::class)->confirm($first, $this->bruno);

        $this->assertSame(1, $purchase->installments()->where('status', 'paid')->count());
        $this->assertSame(5, $purchase->installments()->where('status', 'pending')->count());
    }

    public function test_monthly_totals_count_each_installment_once_and_never_the_purchase(): void
    {
        $this->purchase();
        $this->purchase(30_000, 2, ['description' => 'Tênis']);

        $summary = app(HouseholdSummary::class)->month($this->household, Month::parse('2026-11'));

        $this->assertSame(35_000, $summary['total_cents']);
        $this->assertSame(35_000, $summary['by_source']['credit_card']);
        $this->assertSame(28_000, $summary['by_member'][0]['total_cents']);
        $this->assertSame(7_000, $summary['by_member'][1]['total_cents']);

        $year = app(HouseholdSummary::class)->year($this->household, 2026);
        $this->assertSame(35_000 + 35_000, $year['total_cents'], 'November and December installments only.');
    }

    public function test_the_schedule_can_change_until_an_installment_is_paid(): void
    {
        $purchase = $this->purchase();

        app(CreditCardPurchaseService::class)->update($purchase, $this->leticia, $this->purchaseData(90_000, 3));
        $this->assertSame([30_000, 30_000, 30_000], $purchase->installments()->pluck('amount_cents')->all());

        app(PaymentService::class)->confirm($purchase->installments()->first(), $this->bruno);

        app(CreditCardPurchaseService::class)->update($purchase, $this->leticia, [...$this->purchaseData(90_000, 3), 'description' => 'Notebook gamer']);
        $this->assertSame(['Notebook', 'Notebook gamer', 'Notebook gamer'], $purchase->installments()->pluck('title')->all(), 'Paid installments keep their description.');

        $this->expectException(ValidationException::class);
        app(CreditCardPurchaseService::class)->update($purchase, $this->leticia, $this->purchaseData(120_000, 6));
    }

    public function test_purchases_with_paid_installments_cannot_be_deleted(): void
    {
        $purchase = $this->purchase();
        app(PaymentService::class)->confirm($purchase->installments()->first(), $this->bruno);

        $this->actingAs($this->leticia)
            ->delete(route('credit-card-purchases.destroy', $purchase))
            ->assertSessionHasErrors('purchase');

        $this->assertModelExists($purchase);
    }

    public function test_deleting_a_purchase_removes_its_installments(): void
    {
        $purchase = $this->purchase();

        $this->actingAs($this->bruno)
            ->delete(route('credit-card-purchases.destroy', $purchase))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($purchase);
        $this->assertSame(0, ExpenseOccurrence::withTrashed()->where('credit_card_purchase_id', $purchase->id)->count());
    }

    public function test_installments_cannot_be_deleted_individually(): void
    {
        $installment = $this->purchase()->installments()->first();

        $this->actingAs($this->bruno)
            ->delete(route('expenses.destroy', $installment))
            ->assertSessionHasErrors('expense');

        $this->assertNotSoftDeleted($installment);
    }

    public function test_personal_purchases_are_only_visible_to_their_owner(): void
    {
        $purchase = $this->purchase(overrides: ['scope' => ExpenseScope::Personal]);

        $this->assertSame($this->leticia->id, $purchase->owner_user_id);
        $this->assertSame([], $this->sharesOf($purchase->installments()->first()));

        $this->actingAs($this->bruno)
            ->put(route('credit-card-purchases.update', $purchase), ['description' => 'x'])
            ->assertNotFound();

        $this->actingAs($this->bruno)
            ->delete(route('credit-card-purchases.destroy', $purchase))
            ->assertNotFound();
    }

    public function test_purchases_are_created_through_http(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('credit-card-purchases.store'), [
                'credit_card_id' => $this->card->id,
                'scope' => 'household',
                'description' => 'Ar-condicionado',
                'purchase_date' => '2026-10-20',
                'mode' => 'installments',
                'amount' => '1200.00',
                'installment_count' => 6,
                'first_billing_month' => '2026-11',
            ])
            ->assertSessionHasNoErrors();

        $purchase = CreditCardPurchase::query()->where('description', 'Ar-condicionado')->sole();
        $this->assertSame(6, $purchase->installments()->count());
        $this->assertSame($this->bruno->id, $purchase->created_by_user_id);
    }

    public function test_purchase_validation(): void
    {
        $outsiderCard = CreditCard::factory()->create();

        $this->actingAs($this->bruno)
            ->post(route('credit-card-purchases.store'), [
                'credit_card_id' => $outsiderCard->id,
                'scope' => 'shared',
                'description' => '',
                'purchase_date' => '21/10/2026',
                'mode' => 'installments',
                'amount' => '0.05',
                'installment_count' => 121,
                'first_billing_month' => '2026-13',
            ])
            ->assertSessionHasErrors(['credit_card_id', 'scope', 'description', 'purchase_date', 'installment_count', 'first_billing_month']);

        $this->actingAs($this->bruno)
            ->post(route('credit-card-purchases.store'), [
                'credit_card_id' => $this->card->id,
                'scope' => 'household',
                'description' => 'Chiclete',
                'purchase_date' => '2026-10-21',
                'mode' => 'installments',
                'amount' => '0.05',
                'installment_count' => 6,
                'first_billing_month' => '2026-11',
            ])
            ->assertSessionHasErrors(['amount']);
    }
}
