<?php

namespace App\Http\Controllers\Household;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\HouseholdMemberRequest;
use App\Models\HouseholdMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request): Response
    {
        $household = $this->household($request);

        return Inertia::render('household/members', [
            'householdName' => $household->name,
            'members' => $household->members()->with('user:id,name,email')->get()->map(fn (HouseholdMember $member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'payment_label_preposition' => $member->payment_label_preposition,
                'payment_label' => $member->paymentLabel(),
                'joined_at' => $member->created_at?->toIso8601String(),
            ]),
            'prepositions' => HouseholdMember::PREPOSITIONS,
        ]);
    }

    public function update(HouseholdMemberRequest $request, HouseholdMember $member): RedirectResponse
    {
        Gate::authorize('update', $member);

        $member->update($request->validated());

        $this->toast('Membro atualizado.');

        return back();
    }
}
