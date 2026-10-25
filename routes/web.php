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
use App\Http\Controllers\Knowledge\KnowledgeAttachmentController;
use App\Http\Controllers\Knowledge\KnowledgeBaseController;
use App\Http\Controllers\Knowledge\KnowledgeCategoryController;
use App\Http\Controllers\Knowledge\KnowledgePageController;
use App\Http\Controllers\Knowledge\KnowledgeSubjectController;
use App\Http\Controllers\Shows\ShowChecklistController;
use App\Http\Controllers\Shows\ShowController;
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
    // The checklist of things to take is shared by every show of the household.
    Route::get('shows', [ShowController::class, 'index'])->name('shows.index');
    Route::get('shows/create', [ShowController::class, 'create'])->name('shows.create');
    Route::get('shows/checklist', [ShowChecklistController::class, 'edit'])->name('shows.checklist.edit');
    Route::get('shows/{show}', [ShowController::class, 'show'])->name('shows.show');
    Route::get('shows/{show}/edit', [ShowController::class, 'edit'])->name('shows.edit');

    Route::middleware('throttle:finance-writes')->group(function () {
        Route::post('shows', [ShowController::class, 'store'])->name('shows.store');
        Route::put('shows/checklist', [ShowChecklistController::class, 'update'])->name('shows.checklist.update');
        Route::put('shows/{show}', [ShowController::class, 'update'])->name('shows.update');
        Route::delete('shows/{show}', [ShowController::class, 'destroy'])->name('shows.destroy');
        Route::put('shows/{show}/checklist/{item}', [ShowChecklistController::class, 'check'])->name('shows.checklist.check');
    });

    // Knowledge base: subjects (books), categories, pages and their files.
    // Everything belongs to the signed-in user; files are served only through
    // the authorized attachment route.
    // Old links from when the knowledge base was called "Estudos".
    Route::get('study/{path?}', fn (?string $path = null) => redirect('/knowledge'.($path === null ? '' : '/'.$path), 301))->where('path', '.*');

    Route::prefix('knowledge')->name('knowledge.')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
        Route::get('search', [KnowledgeBaseController::class, 'search'])->name('search');
        Route::get('subjects/{subject}', [KnowledgeSubjectController::class, 'show'])->name('subjects.show');
        Route::get('pages/{page}', [KnowledgePageController::class, 'show'])->name('pages.show');
        Route::get('pages/{page}/edit', [KnowledgePageController::class, 'edit'])->name('pages.edit');
        Route::get('files/{attachment}', [KnowledgeAttachmentController::class, 'show'])->name('attachments.show');

        Route::middleware('throttle:knowledge-writes')->group(function () {
            Route::post('subjects', [KnowledgeSubjectController::class, 'store'])->name('subjects.store');
            Route::put('subjects/order', [KnowledgeSubjectController::class, 'order'])->name('subjects.order');
            Route::put('subjects/{subject}', [KnowledgeSubjectController::class, 'update'])->name('subjects.update');
            Route::delete('subjects/{subject}', [KnowledgeSubjectController::class, 'destroy'])->name('subjects.destroy');
            Route::post('subjects/{subject}/archive', [KnowledgeSubjectController::class, 'archive'])->name('subjects.archive');
            Route::delete('subjects/{subject}/archive', [KnowledgeSubjectController::class, 'restore'])->name('subjects.restore');
            Route::post('subjects/{subject}/pin', [KnowledgeSubjectController::class, 'pin'])->name('subjects.pin');
            Route::delete('subjects/{subject}/pin', [KnowledgeSubjectController::class, 'unpin'])->name('subjects.unpin');
            Route::put('subjects/{subject}/arrange', [KnowledgeSubjectController::class, 'arrange'])->name('subjects.arrange');

            Route::post('subjects/{subject}/categories', [KnowledgeCategoryController::class, 'store'])->name('categories.store');
            Route::put('categories/{category}', [KnowledgeCategoryController::class, 'update'])->name('categories.update');
            Route::put('categories/{category}/move', [KnowledgeCategoryController::class, 'move'])->name('categories.move');
            Route::delete('categories/{category}', [KnowledgeCategoryController::class, 'destroy'])->name('categories.destroy');

            Route::post('subjects/{subject}/pages', [KnowledgePageController::class, 'store'])->name('pages.store');
            Route::put('pages/{page}', [KnowledgePageController::class, 'update'])->name('pages.update');
            Route::put('pages/{page}/content', [KnowledgePageController::class, 'content'])->name('pages.content');
            Route::put('pages/{page}/status', [KnowledgePageController::class, 'status'])->name('pages.status');
            Route::put('pages/{page}/reading', [KnowledgePageController::class, 'reading'])->name('pages.reading');
            Route::post('pages/{page}/bookmark', [KnowledgePageController::class, 'bookmark'])->name('pages.bookmark');
            Route::delete('pages/{page}/bookmark', [KnowledgePageController::class, 'unbookmark'])->name('pages.unbookmark');
            Route::post('pages/{page}/duplicate', [KnowledgePageController::class, 'duplicate'])->name('pages.duplicate');
            Route::put('pages/{page}/move', [KnowledgePageController::class, 'move'])->name('pages.move');
            Route::post('pages/{page}/archive', [KnowledgePageController::class, 'archive'])->name('pages.archive');
            Route::delete('pages/{page}/archive', [KnowledgePageController::class, 'restore'])->name('pages.restore');
            Route::delete('pages/{page}', [KnowledgePageController::class, 'destroy'])->name('pages.destroy');

            Route::post('pages/{page}/attachments', [KnowledgeAttachmentController::class, 'store'])->name('attachments.store');
        });
    });
});

require __DIR__.'/settings.php';
