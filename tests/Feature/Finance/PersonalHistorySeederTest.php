<?php

namespace Tests\Feature\Finance;

use App\Enums\TransactionType;
use App\Models\PersonalTransaction;
use Database\Seeders\PersonalHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class PersonalHistorySeederTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();

        $months = "\tJaneiro\tFevereiro\tMarço\tAbril\tMaio\tJunho\tJulho\tAgosto\tSetembro\tOutubro\tNovembro";

        $this->file = tempnam(sys_get_temp_dir(), 'personal');
        file_put_contents($this->file, implode("\n", [
            'Entrada - 2026',
            $months,
            "Pró-labore\tR$1,000.00\t\t\t\t\t\t\t\t\tR$2,000.00\tR$3,000.00",
            "Extras\tR$10.00",
            "Total Entrada\tR$1,010.00",
            '',
            'Saída - 2026',
            $months,
            "Casa\tR$500.00",
            "Academia\tR$0.00\tR$300.00",
            "Cartão Crédito Nubank\tR$1,234.56\t\t\t\t\t\t\t\t\t-R$33.85",
            "Extras\tR$20.00",
            '',
            'Investimentos - 2026',
            $months,
            "Reserva de Emergência Bruno\tR$200.00\t\t\t\t\t\t\t\t-R$36.62",
            "Fundo Sofia\tR$50.00",
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_it_imports_the_owner_ledger_up_to_october(): void
    {
        $this->importHistory();

        $entries = PersonalTransaction::query()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PersonalTransaction $entry) => [$entry->type, $entry->title, $entry->transaction_date->toDateString(), $entry->amount_cents])
            ->all();

        $this->assertSame([
            [TransactionType::Income, 'Pró-labore', '2026-01-10', 100_000],
            [TransactionType::Income, 'Entradas extras', '2026-01-10', 1_000],
            [TransactionType::Expense, 'Cartão de crédito Nubank', '2026-01-10', 123_456],
            [TransactionType::Expense, 'Saídas extras', '2026-01-10', 2_000],
            [TransactionType::Investment, 'Reserva de emergência', '2026-01-10', 20_000],
            [TransactionType::Expense, 'Academia', '2026-02-10', 30_000],
            [TransactionType::Income, 'Resgate da reserva de emergência', '2026-09-10', 3_662],
            [TransactionType::Income, 'Pró-labore', '2026-10-10', 200_000],
            [TransactionType::Income, 'Crédito cartão Nubank', '2026-10-10', 3_385],
        ], $entries);

        // "Casa", "Fundo Sofia" and months after October are not imported.
        $this->assertTrue(PersonalTransaction::query()->get()->every(fn (PersonalTransaction $entry) => $entry->user_id === $this->bruno->id));
    }

    public function test_running_it_again_does_not_duplicate_entries(): void
    {
        $this->importHistory();
        $this->importHistory();

        $this->assertSame(9, PersonalTransaction::query()->count());
    }

    private function importHistory(): void
    {
        $seeder = app(PersonalHistorySeeder::class);
        $seeder->path = $this->file;
        $seeder->run();
    }
}
