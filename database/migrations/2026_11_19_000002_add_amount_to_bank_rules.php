<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A rule may hold an amount: it then matches only transactions of exactly
     * that value, so one counterparty can have several rules (a PIX of
     * R$ 1.500,00 to someone is the house installment, other values are the
     * gym). Rules without amount match any value. The new unique index is
     * created before the old one is dropped, so the user_id foreign key always
     * has an index to use on MySQL.
     */
    public function up(): void
    {
        Schema::table('bank_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_cents')->nullable()->after('match_mode');
        });

        Schema::table('bank_rules', function (Blueprint $table) {
            $table->unique(['user_id', 'pattern', 'match_mode', 'amount_cents'], 'bank_rules_user_pattern_amount_unique');
        });

        Schema::table('bank_rules', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'pattern', 'match_mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rules that only differ by amount cannot share the old unique key.
        DB::table('bank_rules')->whereNotNull('amount_cents')->delete();

        Schema::table('bank_rules', function (Blueprint $table) {
            $table->unique(['user_id', 'pattern', 'match_mode']);
        });

        Schema::table('bank_rules', function (Blueprint $table) {
            $table->dropUnique('bank_rules_user_pattern_amount_unique');
            $table->dropColumn('amount_cents');
        });
    }
};
