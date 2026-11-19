<?php

use App\Services\Bank\BankCatalogService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Card purchases catalogued as household expenses were due on the day of
     * the purchase whenever the bank had not sent the closing dates of the
     * bills (a purchase on 12/11 stayed in November, unpaid, instead of the
     * bill due on 10/12), and installments of older purchases were due with
     * the first one. The pending ones move forward to the bill that pays
     * them; paid ones and dates set later by hand stay as they are.
     */
    public function up(): void
    {
        app(BankCatalogService::class)->moveCardExpensesToTheirBill();
    }

    /**
     * Reverse the migrations.
     *
     * Nothing to restore: the previous due dates were wrong (each move is in
     * the audit log as bank.moved_to_bill).
     */
    public function down(): void
    {
        //
    }
};
