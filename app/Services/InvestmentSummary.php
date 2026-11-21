<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\BankInvestment;
use App\Models\BankInvestmentSnapshot;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Support\Month;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The member's investments page: the positions of their connected banks with
 * what was put in and the gain, how the money is split by type, how the
 * total balance grew (from the daily snapshots the syncs record) and the
 * contributions entered in Finanças pessoais month by month. Private to the
 * owner, like everything that comes from the bank.
 */
final class InvestmentSummary
{
    /** Months of history and of contributions on the page. */
    public const MONTHS = 12;

    /** Pluggy's investment types, in pt-BR. */
    public const TYPE_LABELS = [
        'FIXED_INCOME' => 'Renda fixa',
        'MUTUAL_FUND' => 'Fundos',
        'EQUITY' => 'Ações',
        'ETF' => 'ETFs',
        'SECURITY' => 'Previdência',
        'COE' => 'COE',
        'OTHER' => 'Outros',
    ];

    /** Pluggy's subtypes that read better in pt-BR; others show the type. */
    public const SUBTYPE_LABELS = [
        'TREASURY' => 'Tesouro Direto',
        'CDB' => 'CDB',
        'RDB' => 'RDB',
        'LCI' => 'LCI',
        'LCA' => 'LCA',
        'LC' => 'LC',
        'CRI' => 'CRI',
        'CRA' => 'CRA',
        'DEBENTURES' => 'Debêntures',
        'CORPORATE_DEBT' => 'Título privado',
        'POUPANCA' => 'Poupança',
        'INVESTMENT_FUND' => 'Fundo de investimento',
        'MULTIMARKET_FUND' => 'Fundo multimercado',
        'FIXED_INCOME_FUND' => 'Fundo de renda fixa',
        'STOCK_FUND' => 'Fundo de ações',
        'ETF_FUND' => 'Fundo de índice',
        'REAL_ESTATE_FUND' => 'Fundo imobiliário',
        'OFFSHORE_FUND' => 'Fundo no exterior',
        'EXCHANGE_FUND' => 'Fundo cambial',
        'STOCK' => 'Ações',
        'BDR' => 'BDR',
        'DERIVATIVES' => 'Derivativos',
        'OPTION' => 'Opções',
        'RETIREMENT' => 'Previdência',
        'PGBL' => 'PGBL',
        'VGBL' => 'VGBL',
    ];

    /**
     * @return array{
     *     totals: array{balance_cents: int, invested_cents: int, gain_cents: int, positions_with_gain: int, positions: int},
     *     positions: list<array<string, mixed>>,
     *     allocation: list<array{type: string, label: string, balance_cents: int}>,
     *     history: list<array{date: string, balance_cents: int}>,
     *     contributions: list<array{month: string, amount_cents: int}>,
     * }
     */
    public function for(User $user): array
    {
        $positions = BankInvestment::query()
            ->where('user_id', $user->id)
            ->with('connection:id,connector_name')
            ->orderByDesc('balance_cents')
            ->orderBy('name')
            ->get();

        $withGain = $positions->filter(fn (BankInvestment $position) => $position->investedCents() !== null);
        $invested = (int) $withGain->sum(fn (BankInvestment $position) => $position->investedCents());

        return [
            'totals' => [
                'balance_cents' => (int) $positions->sum('balance_cents'),
                // Only positions whose invested amount is known count here
                // and in the gain, so the gain is never the whole balance.
                'invested_cents' => $invested,
                'gain_cents' => (int) $withGain->sum('balance_cents') - $invested,
                'positions_with_gain' => $withGain->count(),
                'positions' => $positions->count(),
            ],
            'positions' => array_values($positions->map(fn (BankInvestment $position) => $this->position($position))->all()),
            'allocation' => $this->allocation($positions),
            'history' => $this->history($user, $positions),
            'contributions' => $this->contributions($user),
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type ?? 'OTHER'] ?? self::TYPE_LABELS['OTHER'];
    }

    /**
     * @return array<string, mixed>
     */
    private function position(BankInvestment $position): array
    {
        $invested = $position->investedCents();

        return [
            'id' => $position->id,
            'name' => $position->name,
            'type' => $position->type,
            'type_label' => self::typeLabel($position->type),
            'kind_label' => self::SUBTYPE_LABELS[$position->subtype ?? ''] ?? self::typeLabel($position->type),
            'institution' => $position->connection->connector_name,
            'balance_cents' => $position->balance_cents,
            'invested_cents' => $invested,
            'gain_cents' => $invested !== null ? $position->balance_cents - $invested : null,
            'withdrawal_cents' => $position->withdrawal_cents,
            'rate' => $position->rate,
            'due_date' => $position->due_date?->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, BankInvestment>  $positions
     * @return list<array{type: string, label: string, balance_cents: int}>
     */
    private function allocation(Collection $positions): array
    {
        return array_values($positions
            ->groupBy(fn (BankInvestment $position) => self::typeLabel($position->type))
            ->map(fn (Collection $group, string $label) => [
                'type' => $group->first()->type ?? 'OTHER',
                'label' => $label,
                'balance_cents' => (int) $group->sum('balance_cents'),
            ])
            ->filter(fn (array $slice) => $slice['balance_cents'] > 0)
            ->sortByDesc('balance_cents')
            ->all());
    }

    /**
     * The total balance on each day a sync recorded. A position missing on a
     * day (its connection did not sync) counts with its last known balance;
     * one withdrawn stops counting after its last day.
     *
     * @param  Collection<int, BankInvestment>  $positions
     * @return list<array{date: string, balance_cents: int}>
     */
    private function history(User $user, Collection $positions): array
    {
        $from = Date::today()->subMonthsNoOverflow(self::MONTHS)->toDateString();
        $current = $positions->pluck('pluggy_id')->flip();

        /** @var Collection<string, Collection<int, BankInvestmentSnapshot>> $byPosition */
        $byPosition = BankInvestmentSnapshot::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $from)
            ->orderBy('date')
            ->get(['pluggy_id', 'date', 'balance_cents'])
            ->groupBy('pluggy_id');

        $dates = $byPosition->flatten(1)
            ->map(fn (BankInvestmentSnapshot $snapshot) => $snapshot->date->toDateString())
            ->unique()
            ->sort()
            ->values();

        // Each position's snapshots by day, walked once in date order.
        $series = $byPosition->map(fn (Collection $snapshots) => [
            'rows' => $snapshots->values(),
            'last' => $snapshots->last()?->date->toDateString(),
            'index' => -1,
        ])->all();

        $history = [];

        foreach ($dates as $date) {
            $total = 0;

            foreach ($series as $pluggyId => &$position) {
                $rows = $position['rows'];

                while ($rows->count() > $position['index'] + 1 && $rows[$position['index'] + 1]->date->toDateString() <= $date) {
                    $position['index']++;
                }

                if ($position['index'] < 0 || ($date > $position['last'] && ! $current->has($pluggyId))) {
                    continue;
                }

                $total += $rows[$position['index']]->balance_cents;
            }
            unset($position);

            $history[] = ['date' => $date, 'balance_cents' => $total];
        }

        return $history;
    }

    /**
     * Investments entered in Finanças pessoais (by hand or from the bank
     * inbox) in each of the last months, the current one included.
     *
     * @return list<array{month: string, amount_cents: int}>
     */
    private function contributions(User $user): array
    {
        $first = Month::current()->subMonthsNoOverflow(self::MONTHS - 1);

        $byMonth = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', TransactionType::Investment)
            ->where('transaction_date', '>=', $first->toDateString())
            ->get(['transaction_date', 'amount_cents'])
            ->groupBy(fn (PersonalTransaction $entry) => Month::key($entry->transaction_date))
            ->map(fn (Collection $entries) => (int) $entries->sum('amount_cents'));

        $months = [];

        for ($month = $first, $i = 0; $i < self::MONTHS; $month = $month->addMonthNoOverflow(), $i++) {
            $key = Month::key($month);
            $months[] = ['month' => $key, 'amount_cents' => $byMonth[$key] ?? 0];
        }

        return $months;
    }
}
