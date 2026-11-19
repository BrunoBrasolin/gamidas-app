<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The house settlement ("Acerto da casa"): one transfer between the two
     * members pays the share each owes on several household expenses. Each
     * row is one expense covered by one transfer, with the member who owed
     * the share and how much it was. A member's bank settles an expense
     * once; the other member's bank may show the same transfer and link it
     * too.
     */
    public function up(): void
    {
        Schema::create('bank_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['expense_occurrence_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_settlement_items');
    }
};
