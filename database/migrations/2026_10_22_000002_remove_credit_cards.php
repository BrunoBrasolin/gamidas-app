<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit cards and purchases are gone: installments are now entered directly
 * on one-off expenses. Existing card installments are kept as one-off
 * expenses with their installment number, amount, due date and payment, and
 * their billing month follows the due date like every one-off expense.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('expense_occurrences')
            ->where('source', 'credit_card')
            ->orderBy('id')
            ->each(function (object $occurrence) {
                $dueDate = substr((string) $occurrence->due_date, 0, 10);

                DB::table('expense_occurrences')->where('id', $occurrence->id)->update([
                    'source' => 'one_off',
                    'billing_month' => substr($dueDate, 0, 8).'01',
                ]);
            });

        Schema::table('expense_occurrences', function (Blueprint $table) {
            $table->dropForeign(['credit_card_purchase_id']);
        });

        Schema::table('expense_occurrences', function (Blueprint $table) {
            $table->dropUnique('occurrences_purchase_installment_unique');
        });

        Schema::table('expense_occurrences', function (Blueprint $table) {
            $table->dropColumn('credit_card_purchase_id');
        });

        Schema::dropIfExists('credit_card_purchases');
        Schema::dropIfExists('credit_cards');
    }

    /**
     * Reverse the migrations. Dropped cards and purchases are not restored;
     * converted installments stay one-off expenses.
     */
    public function down(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('last_four_digits', 4)->nullable();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('due_day');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('credit_card_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->restrictOnDelete();
            $table->string('scope', 20);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->date('purchase_date');
            $table->string('mode', 20);
            $table->unsignedBigInteger('total_amount_cents');
            $table->unsignedSmallInteger('installment_count');
            $table->date('first_billing_month');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'credit_card_id']);
        });

        Schema::table('expense_occurrences', function (Blueprint $table) {
            $table->foreignId('credit_card_purchase_id')->nullable()->after('recurring_expense_id')->constrained()->restrictOnDelete();
            $table->unique(['credit_card_purchase_id', 'installment_number'], 'occurrences_purchase_installment_unique');
        });
    }
};
