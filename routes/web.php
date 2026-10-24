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
use App\Http\Controllers\Shows\ShowController;
use App\Http\Controllers\Study\StudyAttachmentController;
use App\Http\Controllers\Study\StudyChapterController;
use App\Http\Controllers\Study\StudyLibraryController;
use App\Http\Controllers\Study\StudyPageController;
use App\Http\Controllers\Study\StudySubjectController;
use App\Http\Controllers\Time\TimeCategoryController;
use App\Http\Controllers\Time\TimeLogController;
use App\Http\Controllers\Time\TimeReportController;
use App\Http\Controllers\Time\TimeSheetController;
use App\Http\Controllers\Time\TimeTimerController;
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

            Route::post('categories/{category}/timer/start', [TimeTimerController::class, 'start'])->name('timer.start');
            Route::post('categories/{category}/timer/pause', [TimeTimerController::class, 'pause'])->name('timer.pause');
            Route::post('categories/{category}/timer/commit', [TimeTimerController::class, 'commit'])->name('timer.commit');
            Route::delete('categories/{category}/timer', [TimeTimerController::class, 'reset'])->name('timer.reset');

            Route::put('logs', [TimeLogController::class, 'update'])->name('logs.update');
        });
    });

    // Concerts the user attended. Every show belongs to the signed-in user.
    Route::get('shows', [ShowController::class, 'index'])->name('shows.index');
    Route::get('shows/create', [ShowController::class, 'create'])->name('shows.create');
    Route::get('shows/{show}', [ShowController::class, 'show'])->name('shows.show');
    Route::get('shows/{show}/edit', [ShowController::class, 'edit'])->name('shows.edit');

    Route::middleware('throttle:finance-writes')->group(function () {
        Route::post('shows', [ShowController::class, 'store'])->name('shows.store');
        Route::put('shows/{show}', [ShowController::class, 'update'])->name('shows.update');
        Route::delete('shows/{show}', [ShowController::class, 'destroy'])->name('shows.destroy');
    });

    // Study library: subjects (books), chapters, pages and their files.
    // Everything belongs to the signed-in user; files are served only through
    // the authorized attachment route.
    Route::prefix('study')->name('study.')->group(function () {
        Route::get('/', [StudyLibraryController::class, 'index'])->name('index');
        Route::get('search', [StudyLibraryController::class, 'search'])->name('search');
        Route::get('subjects/{subject}', [StudySubjectController::class, 'show'])->name('subjects.show');
        Route::get('pages/{page}', [StudyPageController::class, 'show'])->name('pages.show');
        Route::get('pages/{page}/edit', [StudyPageController::class, 'edit'])->name('pages.edit');
        Route::get('files/{attachment}', [StudyAttachmentController::class, 'show'])->name('attachments.show');

        Route::middleware('throttle:study-writes')->group(function () {
            Route::post('subjects', [StudySubjectController::class, 'store'])->name('subjects.store');
            Route::put('subjects/order', [StudySubjectController::class, 'order'])->name('subjects.order');
            Route::put('subjects/{subject}', [StudySubjectController::class, 'update'])->name('subjects.update');
            Route::delete('subjects/{subject}', [StudySubjectController::class, 'destroy'])->name('subjects.destroy');
            Route::post('subjects/{subject}/archive', [StudySubjectController::class, 'archive'])->name('subjects.archive');
            Route::delete('subjects/{subject}/archive', [StudySubjectController::class, 'restore'])->name('subjects.restore');
            Route::post('subjects/{subject}/pin', [StudySubjectController::class, 'pin'])->name('subjects.pin');
            Route::delete('subjects/{subject}/pin', [StudySubjectController::class, 'unpin'])->name('subjects.unpin');
            Route::put('subjects/{subject}/arrange', [StudySubjectController::class, 'arrange'])->name('subjects.arrange');

            Route::post('subjects/{subject}/chapters', [StudyChapterController::class, 'store'])->name('chapters.store');
            Route::put('chapters/{chapter}', [StudyChapterController::class, 'update'])->name('chapters.update');
            Route::delete('chapters/{chapter}', [StudyChapterController::class, 'destroy'])->name('chapters.destroy');

            Route::post('subjects/{subject}/pages', [StudyPageController::class, 'store'])->name('pages.store');
            Route::put('pages/{page}', [StudyPageController::class, 'update'])->name('pages.update');
            Route::put('pages/{page}/content', [StudyPageController::class, 'content'])->name('pages.content');
            Route::put('pages/{page}/status', [StudyPageController::class, 'status'])->name('pages.status');
            Route::put('pages/{page}/reading', [StudyPageController::class, 'reading'])->name('pages.reading');
            Route::post('pages/{page}/bookmark', [StudyPageController::class, 'bookmark'])->name('pages.bookmark');
            Route::delete('pages/{page}/bookmark', [StudyPageController::class, 'unbookmark'])->name('pages.unbookmark');
            Route::post('pages/{page}/duplicate', [StudyPageController::class, 'duplicate'])->name('pages.duplicate');
            Route::put('pages/{page}/move', [StudyPageController::class, 'move'])->name('pages.move');
            Route::post('pages/{page}/archive', [StudyPageController::class, 'archive'])->name('pages.archive');
            Route::delete('pages/{page}/archive', [StudyPageController::class, 'restore'])->name('pages.restore');
            Route::delete('pages/{page}', [StudyPageController::class, 'destroy'])->name('pages.destroy');

            Route::post('pages/{page}/attachments', [StudyAttachmentController::class, 'store'])->name('attachments.store');
        });
    });
});

require __DIR__.'/settings.php';
