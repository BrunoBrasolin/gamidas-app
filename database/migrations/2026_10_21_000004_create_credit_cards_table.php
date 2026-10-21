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
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 120);
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_card_purchases');
        Schema::dropIfExists('credit_cards');
    }
};
