<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\BankConnection;
use App\Services\InvestmentSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's investments: positions, gains, allocation, history and
 * contributions. Private to the owner.
 */
class InvestmentController extends Controller
{
    use InteractsWithHousehold;

    public function __invoke(Request $request, InvestmentSummary $summary): Response
    {
        $user = $this->user($request);

        return Inertia::render('investments/index', [
            ...$summary->for($user),
            'hasConnections' => BankConnection::query()->where('user_id', $user->id)->exists(),
        ]);
    }
}
