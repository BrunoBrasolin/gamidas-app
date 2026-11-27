<?php

namespace App\Http\Controllers\TierLists;

use App\Enums\TierListKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Services\TierListShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creates and removes the public link of the signed-in user's tier lists.
 */
class TierListShareController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly TierListShareService $shares)
    {
        //
    }

    public function store(Request $request, TierListKind $kind): RedirectResponse
    {
        $this->shares->share($this->user($request), $kind);

        $this->toast('Link público criado.');

        return back();
    }

    public function destroy(Request $request, TierListKind $kind): RedirectResponse
    {
        $this->shares->unshare($this->user($request), $kind);

        $this->toast('Link desativado. A página não está mais pública.');

        return back();
    }
}
