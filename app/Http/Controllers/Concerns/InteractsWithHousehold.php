<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Household;
use App\Models\User;
use App\Services\OccurrenceGenerator;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

trait InteractsWithHousehold
{
    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    protected function household(Request $request): Household
    {
        /** @var Household */
        return $this->user($request)->household();
    }

    /**
     * Make sure recurring expenses are materialized up to the viewed month,
     * so the page is correct even if the scheduler has not run yet.
     */
    protected function ensureGenerated(Household $household, CarbonImmutable $month): void
    {
        $limit = Month::current()->addMonthsNoOverflow((int) config('finance.max_months_ahead'));
        $through = $month->min($limit)->max(OccurrenceGenerator::horizon());

        app(OccurrenceGenerator::class)->generateForHousehold($household, $through);
    }

    /**
     * @param  string|null  $description  A second line with details, shown for longer.
     */
    protected function toast(string $message, string $type = 'success', ?string $description = null): void
    {
        Inertia::flash('toast', array_filter(['type' => $type, 'message' => $message, 'description' => $description], fn (?string $value) => $value !== null));
    }
}
