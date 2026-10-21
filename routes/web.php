<?php

use App\Http\Controllers\Finance\CreditCardController;
use App\Http\Controllers\Finance\CreditCardPurchaseController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\ExpensePaymentController;
use App\Http\Controllers\Finance\HouseholdExpenseController;
use App\Http\Controllers\Finance\PersonalFinanceController;
use App\Http\Controllers\Finance\PersonalTransactionController;
use App\Http\Controllers\Finance\RecurringExpenseController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Household\CategoryController;
use App\Http\Controllers\Household\MemberController;
use App\Http\Controllers\Household\SalaryController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', 'household'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('expenses', [HouseholdExpenseController::class, 'index'])->name('expenses.index');
    Route::get('recurring-expenses', [RecurringExpenseController::class, 'index'])->name('recurring-expenses.index');
    Route::get('credit-cards', [CreditCardController::class, 'index'])->name('credit-cards.index');
    Route::get('personal', PersonalFinanceController::class)->name('personal.index');
    Route::get('personal/{type}', [PersonalTransactionController::class, 'index'])
        ->whereIn('type', ['income', 'expenses', 'investments'])
        ->name('personal.transactions.index');
    Route::get('reports', ReportController::class)->name('reports.index');

    Route::redirect('household', '/household/salaries')->name('household');
    Route::get('household/members', [MemberController::class, 'index'])->name('household.members.index');
    Route::get('household/salaries', [SalaryController::class, 'edit'])->name('household.salaries.edit');
    Route::get('household/categories', [CategoryController::class, 'index'])->name('household.categories.index');

    Route::middleware('throttle:finance-writes')->group(function () {
        Route::post('expenses', [HouseholdExpenseController::class, 'store'])->name('expenses.store');
        Route::put('expenses/{occurrence}', [HouseholdExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{occurrence}', [HouseholdExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::post('expenses/{occurrence}/payment', [ExpensePaymentController::class, 'store'])->name('expenses.payment.store');
        Route::delete('expenses/{occurrence}/payment', [ExpensePaymentController::class, 'destroy'])->name('expenses.payment.destroy');

        Route::post('recurring-expenses', [RecurringExpenseController::class, 'store'])->name('recurring-expenses.store');
        Route::put('recurring-expenses/{recurringExpense}', [RecurringExpenseController::class, 'update'])->name('recurring-expenses.update');
        Route::post('recurring-expenses/{recurringExpense}/end', [RecurringExpenseController::class, 'end'])->name('recurring-expenses.end');
        Route::delete('recurring-expenses/{recurringExpense}', [RecurringExpenseController::class, 'destroy'])->name('recurring-expenses.destroy');

        Route::post('credit-cards', [CreditCardController::class, 'store'])->name('credit-cards.store');
        Route::put('credit-cards/{creditCard}', [CreditCardController::class, 'update'])->name('credit-cards.update');
        Route::delete('credit-cards/{creditCard}', [CreditCardController::class, 'destroy'])->name('credit-cards.destroy');
        Route::post('credit-card-purchases', [CreditCardPurchaseController::class, 'store'])->name('credit-card-purchases.store');
        Route::put('credit-card-purchases/{purchase}', [CreditCardPurchaseController::class, 'update'])->name('credit-card-purchases.update');
        Route::delete('credit-card-purchases/{purchase}', [CreditCardPurchaseController::class, 'destroy'])->name('credit-card-purchases.destroy');

        Route::post('personal/{type}', [PersonalTransactionController::class, 'store'])
            ->whereIn('type', ['income', 'expenses', 'investments'])
            ->name('personal.transactions.store');
        Route::put('personal/transactions/{transaction}', [PersonalTransactionController::class, 'update'])->name('personal.transactions.update');
        Route::delete('personal/transactions/{transaction}', [PersonalTransactionController::class, 'destroy'])->name('personal.transactions.destroy');

        Route::put('household/members/{member}', [MemberController::class, 'update'])->name('household.members.update');
        Route::put('household/salaries', [SalaryController::class, 'update'])->name('household.salaries.update');
        Route::post('household/categories', [CategoryController::class, 'store'])->name('household.categories.store');
        Route::put('household/categories/{category}', [CategoryController::class, 'update'])->name('household.categories.update');
        Route::delete('household/categories/{category}', [CategoryController::class, 'destroy'])->name('household.categories.destroy');
    });
});

require __DIR__.'/settings.php';
