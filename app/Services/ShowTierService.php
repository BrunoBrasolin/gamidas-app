<?php

namespace App\Services;

use App\Enums\Tier;
use App\Models\Show;
use App\Models\ShowTierEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A user's tier list of the shows of their household. The shows are shared,
 * the ranking is each member's own. A show can join the list from its day on;
 * one already there stays even if its date is later moved ahead.
 */
class ShowTierService
{
    /**
     * Every show the user can have on the list: the household's shows that
     * already happened (today included), most recent first, plus any already
     * on the list.
     *
     * @return Collection<int, Show> by id
     */
    public function rankable(User $user): Collection
    {
        $today = Date::today()->toDateString();
        $tiered = ShowTierEntry::query()->where('user_id', $user->id)->pluck('show_id');

        return Show::query()
            ->where('household_id', $user->householdId())
            ->where(fn ($query) => $query->where('show_date', '<=', $today)->orWhereIn('id', $tiered))
            ->orderByDesc('show_date')
            ->orderByDesc('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * The tier list: each tier's shows, in order.
     *
     * @return array<string, list<Show>>
     */
    public function tiers(User $user): array
    {
        $entries = ShowTierEntry::query()
            ->where('user_id', $user->id)
            ->whereHas('show', fn ($query) => $query->where('household_id', $user->householdId()))
            ->with('show')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $tiers = array_fill_keys(Tier::values(), []);

        foreach ($entries as $entry) {
            $tiers[$entry->tier->value][] = $entry->show;
        }

        return $tiers;
    }

    /**
     * The tier of each show on the list.
     *
     * @return array<int, string> tier by show id
     */
    public function tierByShow(User $user): array
    {
        /** @var array<int, string> */
        return ShowTierEntry::query()->toBase()->where('user_id', $user->id)->pluck('tier', 'show_id')->all();
    }

    /**
     * Replace the whole tier list with the given order.
     *
     * @param  array<string, list<int>>  $tiers  Show ids by tier, in order.
     *
     * @throws ValidationException
     */
    public function setTiers(User $user, array $tiers): void
    {
        $ids = array_merge(...array_values($tiers));

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['tiers' => 'Um show só pode estar em um tier.']);
        }

        if (array_diff($ids, $this->rankable($user)->keys()->all()) !== []) {
            throw ValidationException::withMessages(['tiers' => 'Algum show não pode entrar na sua tier list.']);
        }

        DB::transaction(function () use ($user, $tiers) {
            $existing = ShowTierEntry::query()->where('user_id', $user->id)->get()->keyBy('show_id');
            $kept = [];

            foreach (Tier::cases() as $tier) {
                foreach ($tiers[$tier->value] ?? [] as $position => $showId) {
                    $kept[$showId] = true;
                    $entry = $existing->get($showId) ?? new ShowTierEntry(['user_id' => $user->id, 'show_id' => $showId]);
                    $entry->fill(['tier' => $tier, 'position' => $position])->save();
                }
            }

            $gone = $existing->keys()->reject(fn (int $showId) => isset($kept[$showId]));

            foreach ($gone->chunk(200) as $chunk) {
                ShowTierEntry::query()->where('user_id', $user->id)->whereIn('show_id', $chunk->all())->delete();
            }
        });
    }

    /**
     * Put a show at the end of a tier, or take it off the list (null). A
     * show already in that tier keeps its place.
     *
     * @throws ValidationException
     */
    public function setTier(User $user, Show $show, ?Tier $tier): void
    {
        $entry = ShowTierEntry::query()->where('user_id', $user->id)->where('show_id', $show->id)->first();

        if ($tier === null) {
            $entry?->delete();

            return;
        }

        if ($entry?->tier === $tier) {
            return;
        }

        if ($entry === null && ! $this->happened($show)) {
            throw ValidationException::withMessages(['tier' => 'Esse show ainda não aconteceu.']);
        }

        $last = ShowTierEntry::query()->where('user_id', $user->id)->where('tier', $tier->value)->max('position');

        ShowTierEntry::query()->updateOrCreate(
            ['user_id' => $user->id, 'show_id' => $show->id],
            ['tier' => $tier, 'position' => is_numeric($last) ? (int) $last + 1 : 0],
        );
    }

    /** Whether the show's day has come. */
    public function happened(Show $show): bool
    {
        return $show->show_date->toDateString() <= Date::today()->toDateString();
    }
}
