<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CreditCardPurchaseRequest;
use App\Models\CreditCardPurchase;
use App\Services\CreditCardPurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreditCardPurchaseController extends Controller
{
    use InteractsWithHousehold;

    public function store(CreditCardPurchaseRequest $request, CreditCardPurchaseService $purchases): RedirectResponse
    {
        $purchases->create($request->household(), $this->user($request), $request->toData());

        $this->toast('Compra registrada.');

        return back();
    }

    public function update(CreditCardPurchaseRequest $request, CreditCardPurchase $purchase, CreditCardPurchaseService $purchases): RedirectResponse
    {
        Gate::authorize('update', $purchase);

        $purchases->update($purchase, $this->user($request), $request->toData());

        $this->toast('Compra atualizada.');

        return back();
    }

    public function destroy(Request $request, CreditCardPurchase $purchase, CreditCardPurchaseService $purchases): RedirectResponse
    {
        Gate::authorize('delete', $purchase);

        $purchases->delete($purchase, $this->user($request));

        $this->toast('Compra excluída.');

        return back();
    }
}
