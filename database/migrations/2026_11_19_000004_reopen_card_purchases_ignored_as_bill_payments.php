<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Open Finance calls a card purchase "PAGAMENTO" (a payment made with the
     * card) and the classifier read it as paying the bill, so card purchases
     * were ignored on import and never reached the inbox. A bill payment on a
     * card is always money coming in: every outgoing one is a purchase (or a
     * fee, by its description). They get their real channel back, and those
     * ignored automatically go back to the inbox; a decision the owner made
     * stays as it is. A sync with the fixed classifier may already have
     * restored the channel of recent ones, which are still ignored: they are
     * found by the reason they were ignored for.
     */
    public function up(): void
    {
        $cards = DB::table('bank_accounts')->where('type', 'CREDIT')->select('id');

        DB::table('bank_transactions')
            ->whereIn('bank_account_id', $cards)
            ->where('direction', 'out')
            ->where(fn ($query) => $query
                ->where('channel', 'bill_payment')
                ->orWhere('ignore_reason', 'bill_payment'))
            ->select(['id', 'channel', 'description', 'operation_type', 'review_status', 'ignore_reason'])
            ->chunkById(500, function (Collection $rows): void {
                $now = Date::now();
                $channels = ['card' => [], 'fee' => []];
                $reopen = [];

                foreach ($rows as $row) {
                    if ($row->channel === 'bill_payment') {
                        $channels[$this->isFee($row->operation_type, $row->description) ? 'fee' : 'card'][] = $row->id;
                    }

                    if ($row->review_status === 'ignored' && $row->ignore_reason === 'bill_payment') {
                        $reopen[] = $row->id;
                    }
                }

                foreach ($channels as $channel => $ids) {
                    if ($ids !== []) {
                        DB::table('bank_transactions')->whereIn('id', $ids)->update(['channel' => $channel, 'updated_at' => $now]);
                    }
                }

                if ($reopen !== []) {
                    DB::table('bank_transactions')->whereIn('id', $reopen)->update([
                        'review_status' => 'pending',
                        'ignore_reason' => null,
                        'reviewed_at' => null,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to restore: the previous classification was wrong.
     */
    public function down(): void
    {
        //
    }

    /**
     * The classifier's rule for card fees at the time of this migration.
     */
    private function isFee(mixed $operationType, mixed $description): bool
    {
        return in_array($operationType, ['TARIFA', 'OPERACOES_CREDITO_CONTRATADAS_CARTAO'], true)
            || (is_string($description) && preg_match('/\b(iof|juros|tarifa|multa|encargos?|anuidade)\b/iu', $description) === 1);
    }
};
