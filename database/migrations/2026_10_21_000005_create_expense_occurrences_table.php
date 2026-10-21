<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 20);
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credit_card_purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('notes')->nullable();
            $table->date('billing_month');
            $table->date('due_date');
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_customized')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One occurrence per recurring rule and billing month, and one per
            // installment of a purchase. Soft-deleted rows keep their slot so a
            // deliberately removed month is never regenerated.
            $table->unique(['recurring_expense_id', 'billing_month'], 'occurrences_recurring_month_unique');
            $table->unique(['credit_card_purchase_id', 'installment_number'], 'occurrences_purchase_installment_unique');
            $table->index(['household_id', 'scope', 'billing_month'], 'occurrences_household_scope_month_index');
            $table->index(['household_id', 'status', 'due_date'], 'occurrences_household_status_due_index');
            $table->index(['owner_user_id', 'billing_month'], 'occurrences_owner_month_index');
        });

        Schema::create('expense_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('salary_cents');
            $table->unsignedBigInteger('combined_salary_cents');
            $table->timestamps();

            $table->unique(['expense_occurrence_id', 'user_id'], 'expense_shares_occurrence_user_unique');
            $table->index('user_id');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('expense_shares');
        Schema::dropIfExists('expense_occurrences');
    }
};
