<?php

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\Month;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports Bruno's personal ledger from the old spreadsheet (January–October).
 *
 * The amounts are real financial data, so they are never committed: they are
 * read from a tab-separated table at storage/app/private/financas-pessoais.tsv,
 * pasted as is from the spreadsheet. It has three sections ("Entrada",
 * "Saída", "Investimentos"), each with a header row of pt-BR month names and
 * one row per entry, values like "R$1,500.00". Rows not listed in SECTIONS
 * are ignored, as are months after LAST_MONTH and empty and zero cells.
 *
 * Skipped on purpose: "Casa" (the household share is derived automatically),
 * "Fundo Sofia" (already a household expense) and the other member's reserve.
 *
 * A negative cell (card refund, reserve withdrawal) becomes income, so the net
 * flow matches the spreadsheet. Each entry is dated on ENTRY_DAY of its month
 * and already imported entries are skipped, so it can run again.
 *
 * Run with: php artisan db:seed --class=PersonalHistorySeeder --force
 */
class PersonalHistorySeeder extends Seeder
{
    public const FILE = 'storage/app/private/financas-pessoais.tsv';

    private const OWNER = 'Bruno';

    private const YEAR = 2026;

    private const LAST_MONTH = 10;

    private const ENTRY_DAY = 10;

    private const NOTE = 'Importado da planilha';

    /**
     * Section => [spreadsheet row => [type, title in the app, title when negative]].
     */
    private const SECTIONS = [
        'entrada' => [
            'Pró-labore' => [TransactionType::Income, 'Pró-labore', null],
            'Participação de Lucros' => [TransactionType::Income, 'Participação nos lucros', null],
            'Extras' => [TransactionType::Income, 'Entradas extras', null],
        ],
        'saída' => [
            'Internet Pais' => [TransactionType::Expense, 'Internet pais', null],
            'Mesada Lara' => [TransactionType::Expense, 'Mesada Lara', null],
            'Academia' => [TransactionType::Expense, 'Academia', null],
            'FIAP' => [TransactionType::Expense, 'FIAP', null],
            'Cartão Crédito XP' => [TransactionType::Expense, 'Cartão de crédito XP', 'Crédito cartão XP'],
            'Cartão Crédito Nubank' => [TransactionType::Expense, 'Cartão de crédito Nubank', 'Crédito cartão Nubank'],
            'Extras' => [TransactionType::Expense, 'Saídas extras', null],
        ],
        'investimentos' => [
            'Reserva de Emergência Bruno' => [TransactionType::Investment, 'Reserva de emergência', 'Resgate da reserva de emergência'],
        ],
    ];

    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
        'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    /**
     * Overrides the table location (used by tests).
     */
    public ?string $path = null;

    public function run(): void
    {
        $owner = User::query()->where('name', 'like', self::OWNER.'%')->sole();
        $entries = $this->readTable($this->path ?? base_path(self::FILE));

        DB::transaction(function () use ($owner, $entries) {
            $created = 0;

            foreach ($entries as [$type, $title, $date, $cents]) {
                $entry = PersonalTransaction::query()->firstOrCreate(
                    ['user_id' => $owner->id, 'type' => $type, 'title' => $title, 'transaction_date' => $date],
                    ['amount_cents' => $cents, 'notes' => self::NOTE],
                );

                $created += $entry->wasRecentlyCreated ? 1 : 0;
            }

            $this->command?->info(sprintf('%d lançamentos importados (%d já existiam).', $created, count($entries) - $created));
        });
    }

    /**
     * @return list<array{TransactionType, string, string, int}> [type, title, date, cents]
     */
    private function readTable(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Arquivo de finanças pessoais não encontrado: {$path}");
        }

        $entries = [];
        $section = null;
        $months = [];

        foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [] as $line) {
            $cells = array_map(trim(...), explode("\t", $line));
            $label = array_shift($cells);

            if ($label === '' && in_array('Janeiro', $cells, true)) {
                $months = array_map(fn (string $name) => self::MONTHS[mb_strtolower($name)] ?? null, $cells);

                continue;
            }

            // Section titles look like "Entrada - 2026".
            $heading = mb_strtolower(trim(explode(' - ', $label)[0]));

            if (array_key_exists($heading, self::SECTIONS)) {
                $section = $heading;
                $months = [];

                continue;
            }

            if ($section === null || ! array_key_exists($label, self::SECTIONS[$section])) {
                continue;
            }

            [$type, $title, $negativeTitle] = self::SECTIONS[$section][$label];

            foreach ($cells as $index => $cell) {
                $month = $months[$index] ?? null;
                $value = str_replace(['R$', ','], '', $cell);

                if ($month === null || $month > self::LAST_MONTH || $value === '') {
                    continue;
                }

                $negative = str_starts_with($value, '-');
                $cents = Money::toCents(ltrim($value, '-'));

                if ($cents === 0) {
                    continue;
                }

                if ($negative && $negativeTitle === null) {
                    throw new RuntimeException("Valor negativo inesperado em {$label}: {$cell}");
                }

                $entries[] = [
                    $negative ? TransactionType::Income : $type,
                    $negative ? $negativeTitle : $title,
                    Month::dueDate(Month::parse(sprintf('%04d-%02d', self::YEAR, $month)), self::ENTRY_DAY)->toDateString(),
                    $cents,
                ];
            }
        }

        return $entries;
    }
}
