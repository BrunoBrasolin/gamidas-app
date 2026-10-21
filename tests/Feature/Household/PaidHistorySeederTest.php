<?php

namespace Tests\Feature\Household;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Models\ExpenseOccurrence;
use App\Models\PaymentEvent;
use App\Models\RecurringExpense;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Database\Seeders\PaidHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class PaidHistorySeederTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();

        $this->file = tempnam(sys_get_temp_dir(), 'history');
        file_put_contents($this->file, implode("\n", [
            "\tJaneiro\tFevereiro\tMarço",
            "Sofia\tR$0.00\tR$10.00\tR$10.00",
            "Internet\tR$100.00\tR$100.00\tR$1,050.50",
            "Mercado\tR$500.00\t\tR$600.00",
            "Extras PIX\t\tR$480.00\tR$0.00",
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_it_imports_paid_months_into_existing_templates_and_one_offs(): void
    {
        $internet = $this->createTemplate('Internet', 12_000, 17);
        $this->createTemplate('Fundo Sofia', 1_000, 10);

        $this->importHistory();

        $internet->refresh();
        $this->assertSame('2026-01', Month::key($internet->start_month));

        $january = $internet->occurrences()->where('billing_month', '2026-01-01')->sole();
        $this->assertSame(OccurrenceStatus::Paid, $january->status);
        $this->assertSame(10_000, $january->amount_cents);
        $this->assertTrue($january->is_customized);
        $this->assertSame('2026-01-17', $january->due_date->toDateString());
        $this->assertSame($this->leticia->id, $january->paid_by_user_id);
        $this->assertSame([$this->bruno->id => 8_000, $this->leticia->id => 2_000], $this->sharesOf($january));
        $this->assertSame(105_050, $internet->occurrences()->where('billing_month', '2026-03-01')->value('amount_cents'));

        // A zero cell is skipped: the fund starts in February.
        $this->assertSame(2, ExpenseOccurrence::query()->where('title', 'Fundo Sofia')->where('status', OccurrenceStatus::Paid)->count());

        $groceries = ExpenseOccurrence::query()->where('title', 'Mercado')->orderBy('billing_month')->get();
        $this->assertCount(2, $groceries);
        $this->assertSame(OccurrenceSource::OneOff, $groceries[0]->source);
        $this->assertSame([50_000, 60_000], $groceries->pluck('amount_cents')->all());
        $this->assertTrue($groceries->every(fn (ExpenseOccurrence $occurrence) => $occurrence->isPaid()));

        // Rows outside the import list are ignored.
        $this->assertFalse(ExpenseOccurrence::query()->where('title', 'Extras PIX')->exists());

        $this->assertSame(7, PaymentEvent::query()->count());
    }

    public function test_running_it_twice_does_not_duplicate_months(): void
    {
        $this->createTemplate('Internet', 12_000, 17);

        $this->importHistory();
        $this->importHistory();

        $this->assertSame(3, ExpenseOccurrence::query()->where('title', 'Internet')->where('status', OccurrenceStatus::Paid)->count());
        $this->assertSame(2, ExpenseOccurrence::query()->where('title', 'Mercado')->count());
        $this->assertSame(7, PaymentEvent::query()->count());
    }

    private function importHistory(): void
    {
        $seeder = app(PaidHistorySeeder::class);
        $seeder->path = $this->file;
        $seeder->run();
    }

    private function createTemplate(string $title, int $amountCents, int $dueDay): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => $title,
            'notes' => null,
            'amount_cents' => $amountCents,
            'due_day' => $dueDay,
            'start_month' => Month::current(),
            'end_month' => null,
            'total_months' => null,
        ]);
    }
}
