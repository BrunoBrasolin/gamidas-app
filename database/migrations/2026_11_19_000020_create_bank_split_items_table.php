<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A transaction split into parts ("Dividir valor"): one card purchase or
     * PIX that is partly an expense that already exists and partly a
     * personal expense, for example. Each row is one part, with its value
     * and what it became — a household expense it created or an existing
     * one it was linked to, or an entry in the owner's personal ledger. The
     * parts add up to the transaction.
     */
    public function up(): void
    {
        Schema::create('bank_split_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('expense_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('personal_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_split_items');
    }
};
