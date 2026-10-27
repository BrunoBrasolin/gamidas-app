<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Limit how often records can be written by a single user.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('finance-writes', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Time tracking writes more often (inline sheet edits, note autosave).
        RateLimiter::for('time-writes', fn (Request $request) => Limit::perMinute(180)->by($request->user()?->id ?: $request->ip()));

        // The knowledge editor autosaves, uploads files and records the reading position.
        RateLimiter::for('knowledge-writes', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        // The attention game is public and a whole classroom may share one
        // IP (school Wi-Fi), so the limits are per IP but generous.
        RateLimiter::for('game-writes', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        RateLimiter::for('game-reads', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));

        // Webhooks from the user's own servers (Jellyfin), before the token is checked.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
