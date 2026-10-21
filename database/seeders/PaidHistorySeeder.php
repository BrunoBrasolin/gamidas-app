<?php

namespace Database\Seeders;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Enums\PaymentEventType;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\PaymentEvent;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShareCalculator;
use App\Support\Money;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports household expenses already paid before the app was in use.
 *
 * The amounts are real financial data, so they are never committed: they are
 * read from a tab-separated table at storage/app/private/historico-pagamentos.tsv
 * (header row with pt-BR month names, one row per expense, values like
 * "R$1,500.00"). Rows not listed in ROWS are ignored, as are empty and zero
 * cells.
 *
 * Rows matching an existing household recurring expense become paid months of
 * it (its start month is moved back to the first imported month); the others
 * become paid one-off expenses. Shares use the current salaries, the creator
 * is the template's creator (or the first member) and the payment is confirmed
 * by the other member. Already imported months are skipped, so it can run again.
 *
 * Run with: php artisan db:seed --class=PaidHistorySeeder --force
 */
class PaidHistorySeeder extends Seeder
{
    public const FILE = 'storage/app/private/historico-pagamentos.tsv';

    private const YEAR = 2026;

    private const DUE_DAY = 10;

    /**
     * Spreadsheet row => expense title in the app.
     */
    private const ROWS = [
        'Sofia' => 'Fundo Sofia',
        'Água' => 'Água',
        'Internet' => 'Internet',
        'Luz' => 'Luz',
        'Parcela Casa' => 'Parcela Casa',
        'IPTU' => 'IPTU',
        'Mercado' => 'Mercado',
        'Seguro Moto' => 'Seguro Moto',
    ];

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /**
     * Overrides the table location (used by tests).
     */
    public ?string $path = null;

    public function __construct(
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    public function run(): void
    {
        $household = Household::query()->sole();
        $members = $household->users()->get();

        if ($members->count() !== 2) {
            throw new RuntimeException('O histórico só pode ser importado em uma residência com dois membros.');
        }

        $rows = $this->readTable($this->path ?? base_path(self::FILE));

        DB::transaction(function () use ($household, $members, $rows) {
            foreach ($rows as $title => $amounts) {
                $template = RecurringExpense::query()
                    ->where('household_id', $household->id)
                    ->where('scope', ExpenseScope::Household)
                    ->where('title', $title)
                    ->first();

                $creator = $template !== null ? $members->firstWhere('id', $template->created_by_user_id) : $members->first();
                $payer = $members->firstWhere('id', '!=', $creator->id);

                foreach ($amounts as $monthKey => $cents) {
                    $this->importMonth($household, $template, $title, Month::parse($monthKey), $cents, $creator, $payer);
                }

                $first = Month::parse(array_key_first($amounts));

                if ($template !== null && $first->lessThan($template->start_month)) {
                    $template->update(['start_month' => $first]);
                }

                $this->command?->info(sprintf('%s: %d meses (%s)', $title, count($amounts), $template !== null ? 'recorrente' : 'avulsa'));
            }
        });
    }

    private function importMonth(Household $household, ?RecurringExpense $template, string $title, CarbonImmutable $month, int $cents, User $creator, User $payer): void
    {
        $existing = ExpenseOccurrence::query()
            ->withTrashed()
            ->where('household_id', $household->id)
            ->where('billing_month', $month->toDateString())
            ->when(
                $template !== null,
                fn ($query) => $query->where('recurring_expense_id', $template?->id),
                fn ($query) => $query->where('source', OccurrenceSource::OneOff)->where('title', $title),
            )
            ->first();

        if ($existing !== null && ($existing->isPaid() || $existing->trashed())) {
            return;
        }

        $occurrence = $existing ?? new ExpenseOccurrence([
            'household_id' => $household->id,
            'scope' => ExpenseScope::Household,
            'source' => $template !== null ? OccurrenceSource::Recurring : OccurrenceSource::OneOff,
            'recurring_expense_id' => $template?->id,
            'billing_month' => $month,
            'created_by_user_id' => $creator->id,
        ]);

        $dueDate = Month::dueDate($month, $template->due_day ?? self::DUE_DAY);

        $occurrence->fill([
            'title' => $template->title ?? $title,
            'amount_cents' => $cents,
            'due_date' => $dueDate,
            'is_customized' => $template !== null && $cents !== $template->amount_cents,
        ])->save();

        $this->shares->sync($occurrence);

        $paidAt = $dueDate->setTime(12, 0);

        $occurrence->update([
            'status' => OccurrenceStatus::Paid,
            'paid_at' => $paidAt,
            'paid_by_user_id' => $payer->id,
        ]);

        PaymentEvent::create([
            'expense_occurrence_id' => $occurrence->id,
            'user_id' => $payer->id,
            'type' => PaymentEventType::Paid,
            'amount_cents' => $cents,
            'reason' => 'Importado do histórico',
            'created_at' => $paidAt,
        ]);

        $this->audit->record('payment.confirmed', $occurrence, $payer, $household->id, null, [
            'amount_cents' => $cents,
            'shares' => $occurrence->shares()->pluck('amount_cents', 'user_id')->all(),
            'imported' => true,
        ]);
    }

    /**
     * @return array<string, array<string, int>> Title => ["YYYY-MM" => cents], months in order.
     */
    private function readTable(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Arquivo de histórico não encontrado: {$path}");
        }

        $lines = array_values(array_filter(
            preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [],
            fn (string $line) => trim($line) !== '',
        ));

        $months = array_map(function (string $name) {
            $name = mb_strtolower(trim($name));

            return $name === '' ? null : sprintf('%04d-%02d', self::YEAR, self::MONTHS[$name] ?? throw new RuntimeException("Mês desconhecido: {$name}"));
        }, explode("\t", array_shift($lines) ?? ''));

        $rows = [];

        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            $label = trim((string) array_shift($cells));

            if (! array_key_exists($label, self::ROWS)) {
                continue;
            }

            // The header may or may not have a leading empty cell for the label column.
            $offset = $months[0] === null ? 1 : 0;

            foreach ($cells as $index => $cell) {
                $value = str_replace(['R$', ','], '', trim($cell));
                $month = $months[$index + $offset] ?? null;

                if ($month === null || $value === '' || ($cents = Money::toCents($value)) === 0) {
                    continue;
                }

                $rows[self::ROWS[$label]][$month] = $cents;
            }
        }

        return $rows;
    }
}
