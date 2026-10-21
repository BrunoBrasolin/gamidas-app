<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses and personal transactions no longer have a category: only a title
 * and notes. Purchases and personal transactions rename "description" to
 * "title" to match recurring expenses and occurrences.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['expense_occurrences', 'credit_card_purchases', 'recurring_expenses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('expense_category_id');
            });
        }

        Schema::table('credit_card_purchases', function (Blueprint $table) {
            $table->renameColumn('description', 'title');
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->renameColumn('description', 'title');
        });

        Schema::dropIfExists('expense_categories');
    }

    /**
     * Reverse the migrations. Dropped categories are not restored.
     */
    public function down(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->renameColumn('title', 'description');
        });

        Schema::table('personal_transactions', function (Blueprint $table) {
            $table->string('category', 60)->default('');
        });

        Schema::table('credit_card_purchases', function (Blueprint $table) {
            $table->renameColumn('title', 'description');
        });

        foreach (['recurring_expenses', 'credit_card_purchases', 'expense_occurrences'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            });
        }
    }
};
