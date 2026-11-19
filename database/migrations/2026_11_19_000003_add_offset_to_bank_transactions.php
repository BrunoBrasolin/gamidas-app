<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A refund and the charge it reverses cancel each other ("compensação"):
     * both are ignored and point at one another, so nothing reaches the
     * expenses and the net is zero.
     */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('offset_transaction_id')
                ->nullable()
                ->after('personal_transaction_id')
                ->constrained('bank_transactions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offset_transaction_id');
        });
    }
};
