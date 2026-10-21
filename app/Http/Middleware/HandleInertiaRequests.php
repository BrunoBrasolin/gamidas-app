<?php

namespace App\Http\Middleware;

use App\Models\HouseholdMember;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'household' => fn () => $this->household($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The household of the authenticated user, shared with every page.
     *
     * @return array{id: int, name: string, members: list<array{user_id: int, name: string, first_name: string}>}|null
     */
    private function household(Request $request): ?array
    {
        $household = $request->user()?->household();

        if ($household === null) {
            return null;
        }

        return [
            'id' => $household->id,
            'name' => $household->name,
            'members' => array_values($household->members()->with('user:id,name')->get()->map(fn (HouseholdMember $member) => [
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'first_name' => $member->user->firstName(),
            ])->all()),
        ];
    }
}
