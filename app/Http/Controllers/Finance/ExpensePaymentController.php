<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReopenPaymentRequest;
use App\Models\ExpenseOccurrence;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExpensePaymentController extends Controller
{
    use InteractsWithHousehold;

    /**
     * Confirm the payment of an occurrence.
     */
    public function store(Request $request, ExpenseOccurrence $occurrence, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('confirmPayment', $occurrence);

        $payments->confirm($occurrence, $this->user($request));

        $this->toast('Pagamento confirmado.');

        return back();
    }

    /**
     * Reopen a paid occurrence, recording the reason.
     */
    public function destroy(ReopenPaymentRequest $request, ExpenseOccurrence $occurrence, PaymentService $payments): RedirectResponse
    {
        Gate::authorize('reopenPayment', $occurrence);

        $payments->reopen($occurrence, $this->user($request), trim((string) $request->validated('reason')));

        $this->toast('Pagamento reaberto.', 'info');

        return back();
    }
}
