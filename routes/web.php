<?php

use App\Http\Controllers\Calendar\CalendarEventController;
use App\Http\Controllers\Finance\DashboardController;
use App\Http\Controllers\Finance\ExpensePaymentController;
use App\Http\Controllers\Finance\HouseholdExpenseController;
use App\Http\Controllers\Finance\PersonalFinanceController;
use App\Http\Controllers\Finance\PersonalTransactionController;
use App\Http\Controllers\Finance\RecurringExpenseController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Household\MemberController;
use App\Http\Controllers\Household\SalaryController;
use App\Http\Controllers\Time\TimeCategoryController;
use App\Http\Controllers\Time\TimeLogController;
use App\Http\Controllers\Time\TimeReportController;
use App\Http\Controllers\Time\TimeSheetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', 'household'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('dashboard/calendar', [CalendarEventController::class, 'index'])->name('calendar.index');
    Route::get('expenses', [HouseholdExpenseController::class, 'index'])->name('expenses.index');
    Route::get('recurring-expenses', [RecurringExpenseController::class, 'index'])->name('recurring-expenses.index');
    Route::get('personal', PersonalFinanceController::class)->name('personal.index');
    Route::get('personal/{type}', [PersonalTransactionController::class, 'index'])
        ->whereIn('type', ['income', 'expenses', 'investments'])
        ->name('personal.transactions.index');
    Route::get('reports', ReportController::class)->name('reports.index');

    Route::redirect('household', '/household/salaries')->name('household');
    Route::get('household/members', [MemberController::class, 'index'])->name('household.members.index');
    Route::get('household/salaries', [SalaryController::class, 'edit'])->name('household.salaries.edit');

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

        Route::post('personal/{type}', [PersonalTransactionController::class, 'store'])
            ->whereIn('type', ['income', 'expenses', 'investments'])
            ->name('personal.transactions.store');
        Route::put('personal/transactions/{transaction}', [PersonalTransactionController::class, 'update'])->name('personal.transactions.update');
        Route::delete('personal/transactions/{transaction}', [PersonalTransactionController::class, 'destroy'])->name('personal.transactions.destroy');

        Route::post('dashboard/calendar/events', [CalendarEventController::class, 'store'])->name('calendar.events.store');
        Route::put('dashboard/calendar/events/{event}', [CalendarEventController::class, 'update'])->name('calendar.events.update');
        Route::delete('dashboard/calendar/events/{event}', [CalendarEventController::class, 'destroy'])->name('calendar.events.destroy');

        Route::put('household/members/{member}', [MemberController::class, 'update'])->name('household.members.update');
        Route::put('household/salaries', [SalaryController::class, 'update'])->name('household.salaries.update');
    });

    // Personal time tracking by category. Every record belongs to the signed-in user.
    Route::prefix('time')->name('time.')->group(function () {
        Route::get('/', TimeSheetController::class)->name('index');
        Route::get('categories', [TimeCategoryController::class, 'index'])->name('categories.index');
        Route::get('reports', TimeReportController::class)->name('reports.index');

        Route::middleware('throttle:time-writes')->group(function () {
            Route::post('categories', [TimeCategoryController::class, 'store'])->name('categories.store');
            Route::put('categories/{category}', [TimeCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [TimeCategoryController::class, 'destroy'])->name('categories.destroy');
            Route::post('categories/{category}/archive', [TimeCategoryController::class, 'archive'])->name('categories.archive');
            Route::delete('categories/{category}/archive', [TimeCategoryController::class, 'restore'])->name('categories.restore');

            Route::put('logs', [TimeLogController::class, 'update'])->name('logs.update');
        });
    });
});

require __DIR__.'/settings.php';
