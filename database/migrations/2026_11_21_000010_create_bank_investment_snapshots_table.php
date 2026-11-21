<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily balance of each investment position, so the investments page can
 * chart how the money grew (Pluggy only tells the current balance), plus the
 * profit and the net amount to withdraw that Pluggy reports per position.
 * The history starts with today's balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_investments', function (Blueprint $table) {
            $table->bigInteger('profit_cents')->nullable()->after('invested_cents');
            $table->bigInteger('withdrawal_cents')->nullable()->after('profit_cents');
        });

        Schema::create('bank_investment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_id', 64);
            $table->date('date');
            $table->bigInteger('balance_cents');
            $table->bigInteger('invested_cents')->nullable();
            $table->timestamps();

            $table->unique(['pluggy_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        $today = Date::today()->toDateString();
        $now = Date::now();

        DB::table('bank_investments')->orderBy('id')->each(function (object $investment) use ($today, $now) {
            DB::table('bank_investment_snapshots')->insert([
                'bank_connection_id' => $investment->bank_connection_id,
                'user_id' => $investment->user_id,
                'pluggy_id' => $investment->pluggy_id,
                'date' => $today,
                'balance_cents' => $investment->balance_cents,
                'invested_cents' => $investment->invested_cents,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_investment_snapshots');

        Schema::table('bank_investments', function (Blueprint $table) {
            $table->dropColumn(['profit_cents', 'withdrawal_cents']);
        });
    }
};
