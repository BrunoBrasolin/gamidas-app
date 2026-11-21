<?php

namespace Tests\Feature\Finance;

use App\Enums\TransactionType;
use App\Models\BankConnection;
use App\Models\BankInvestment;
use App\Models\BankInvestmentSnapshot;
use App\Models\PersonalTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class InvestmentPageTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-21 12:00:00');
        $this->createHousehold();
        $this->connection = $this->createBankConnection($this->bruno);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function position(string $pluggyId, string $type, int $balance, ?int $invested): BankInvestment
    {
        return BankInvestment::create([
            'bank_connection_id' => $this->connection->id,
            'user_id' => $this->bruno->id,
            'pluggy_id' => $pluggyId,
            'name' => 'Posição '.$pluggyId,
            'type' => $type,
            'balance_cents' => $balance,
            'invested_cents' => $invested,
        ]);
    }

    private function snapshot(string $pluggyId, string $date, int $balance): void
    {
        BankInvestmentSnapshot::create([
            'bank_connection_id' => $this->connection->id,
            'user_id' => $this->bruno->id,
            'pluggy_id' => $pluggyId,
            'date' => $date,
            'balance_cents' => $balance,
        ]);
    }

    public function test_the_page_shows_positions_gains_and_allocation(): void
    {
        $this->position('cdb', 'FIXED_INCOME', 110_000, 100_000);
        $this->position('fund', 'MUTUAL_FUND', 50_000, null);
        $this->position('rdb', 'FIXED_INCOME', 40_000, 42_000);

        $this->actingAs($this->bruno)
            ->get(route('investments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('investments/index')
                ->where('hasConnections', true)
                ->where('totals.balance_cents', 200_000)
                // Only positions with a known invested amount count in the gain.
                ->where('totals.invested_cents', 142_000)
                ->where('totals.gain_cents', 8_000)
                ->where('totals.positions_with_gain', 2)
                ->where('totals.positions', 3)
                ->has('positions', 3)
                ->where('positions.0.name', 'Posição cdb')
                ->where('positions.0.kind_label', 'Renda fixa')
                ->where('positions.0.gain_cents', 10_000)
                ->where('positions.1.gain_cents', null)
                ->where('positions.2.gain_cents', -2_000)
                ->where('allocation', [
                    ['type' => 'FIXED_INCOME', 'label' => 'Renda fixa', 'balance_cents' => 150_000],
                    ['type' => 'MUTUAL_FUND', 'label' => 'Fundos', 'balance_cents' => 50_000],
                ]));
    }

    public function test_the_history_fills_gaps_and_drops_withdrawn_positions(): void
    {
        $this->position('cdb', 'FIXED_INCOME', 1_300, 1_000);

        // The CDB missed a day's sync (19th): it counts with its last balance.
        $this->snapshot('cdb', '2026-11-18', 1_100);
        $this->snapshot('cdb', '2026-11-20', 1_300);
        // A position withdrawn after the 19th stops counting.
        $this->snapshot('old', '2026-11-18', 500);
        $this->snapshot('old', '2026-11-19', 600);
        // Older than 12 months: out of the chart.
        $this->snapshot('cdb', '2025-10-01', 900);

        $this->actingAs($this->bruno)
            ->get(route('investments.index'))
            ->assertInertia(fn (Assert $page) => $page->where('history', [
                ['date' => '2026-11-18', 'balance_cents' => 1_600],
                ['date' => '2026-11-19', 'balance_cents' => 1_700],
                ['date' => '2026-11-20', 'balance_cents' => 1_300],
            ]));
    }

    public function test_contributions_are_the_investments_entered_each_month(): void
    {
        foreach ([['2026-11-05', 30_000], ['2026-11-20', 20_000], ['2026-09-10', 15_000], ['2025-11-30', 99_000]] as [$date, $cents]) {
            PersonalTransaction::create(['user_id' => $this->bruno->id, 'type' => TransactionType::Investment, 'title' => 'Aporte', 'amount_cents' => $cents, 'transaction_date' => $date]);
        }
        PersonalTransaction::create(['user_id' => $this->bruno->id, 'type' => TransactionType::Expense, 'title' => 'Mercado', 'amount_cents' => 7_000, 'transaction_date' => '2026-11-05']);
        PersonalTransaction::create(['user_id' => $this->leticia->id, 'type' => TransactionType::Investment, 'title' => 'Dela', 'amount_cents' => 1_000, 'transaction_date' => '2026-11-05']);

        $this->actingAs($this->bruno)
            ->get(route('investments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions', 12)
                ->where('contributions.0', ['month' => '2025-12', 'amount_cents' => 0])
                ->where('contributions.9', ['month' => '2026-09', 'amount_cents' => 15_000])
                ->where('contributions.11', ['month' => '2026-11', 'amount_cents' => 50_000]));
    }

    public function test_investments_are_private_to_their_owner(): void
    {
        $this->position('cdb', 'FIXED_INCOME', 110_000, 100_000);
        $this->snapshot('cdb', '2026-11-20', 110_000);

        $this->actingAs($this->leticia)
            ->get(route('investments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('hasConnections', false)
                ->where('totals.balance_cents', 0)
                ->has('positions', 0)
                ->where('history', []));
    }
}
