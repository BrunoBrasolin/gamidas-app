<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Open Finance data imported from Pluggy. Every row belongs to the member
     * who connected the bank (personal finances are private to their owner);
     * a transaction only reaches the household when its owner catalogues it
     * as a household expense. Amounts are integer cents: transactions keep the
     * absolute value plus a direction, balances may be negative.
     */
    public function up(): void
    {
        // A Pluggy "item": one bank login/consent of one member. The item id
        // is stored encrypted; its SHA-256 finds the connection from webhooks.
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('item_id');
            $table->char('item_hash', 64)->unique();
            $table->unsignedInteger('connector_id')->nullable();
            $table->string('connector_name', 120);
            $table->string('connector_image_url', 500)->nullable();
            $table->string('connector_color', 16)->nullable();
            $table->string('status', 32);
            $table->string('execution_status', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('consent_expires_at')->nullable();
            $table->dateTime('bank_updated_at')->nullable();
            $table->date('import_from');
            $table->dateTime('synced_at')->nullable();
            $table->dateTime('sync_requested_at')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_id', 64)->unique();
            $table->string('type', 16);
            $table->string('subtype', 32)->nullable();
            $table->string('name', 160);
            $table->string('number', 32)->nullable();
            $table->bigInteger('balance_cents')->default(0);
            $table->string('currency_code', 3)->default('BRL');
            $table->bigInteger('credit_limit_cents')->nullable();
            $table->bigInteger('available_credit_cents')->nullable();
            $table->date('balance_close_date')->nullable();
            $table->date('balance_due_date')->nullable();
            $table->timestamps();
        });

        // Credit card bills ("faturas"). They are shown for reference and give
        // card purchases their due date; they never become expenses.
        Schema::create('bank_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_id', 64)->unique();
            $table->date('closing_date')->nullable();
            $table->date('due_date');
            $table->bigInteger('total_cents');
            $table->bigInteger('minimum_payment_cents')->nullable();
            $table->unsignedBigInteger('finance_charges_cents')->default(0);
            $table->timestamps();

            $table->index(['bank_account_id', 'due_date']);
        });

        Schema::create('bank_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_id', 64)->unique();
            $table->string('name', 160);
            $table->string('type', 32)->nullable();
            $table->string('subtype', 64)->nullable();
            $table->string('status', 16)->nullable();
            $table->bigInteger('balance_cents')->default(0);
            $table->bigInteger('invested_cents')->nullable();
            $table->string('rate', 64)->nullable();
            $table->date('due_date')->nullable();
            $table->dateTime('balance_at')->nullable();
            $table->timestamps();
        });

        // What the owner taught the inbox: transactions whose counterparty or
        // description match the pattern get this classification suggested
        // (or applied right away, with auto_apply) the next time.
        Schema::create('bank_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pattern', 160);
            $table->string('match_mode', 16)->default('equals');
            $table->string('label', 160);
            $table->string('action', 16);
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->boolean('auto_apply')->default(false);
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'pattern', 'match_mode']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_id', 64)->unique();
            $table->date('date');
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_cents');
            $table->string('direction', 3);
            $table->string('channel', 16);
            $table->string('bank_status', 16);
            $table->string('operation_type', 48)->nullable();
            $table->string('counterparty_name', 160)->nullable();
            $table->string('counterparty_document', 32)->nullable();
            $table->string('match_key', 160);
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->string('pluggy_bill_id', 64)->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('review_status', 16)->default('pending');
            $table->string('catalog_type', 16)->nullable();
            $table->string('ignore_reason', 16)->nullable();
            $table->foreignId('expense_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('personal_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'review_status', 'date']);
            $table->index(['bank_account_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_rules');
        Schema::dropIfExists('bank_investments');
        Schema::dropIfExists('bank_bills');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_connections');
    }
};
