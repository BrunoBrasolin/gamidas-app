<?php

namespace App\Services\Steam;

use App\Enums\BacklogStatus;
use App\Enums\Tier;
use App\Models\SteamBacklogEntry;
use App\Models\SteamGame;
use App\Models\SteamTierEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The two lists a user keeps of their Steam games: the tier list and the
 * games to finish ("para zerar"). Entries belong to the user and name the
 * game by app id, so they outlive the Steam account's connection; a game
 * can join a list only while it is in the user's library.
 *
 * @phpstan-type ListGame array{app_id: int, name: string, game_id: int|null, playtime_minutes: int|null, last_played_at: string|null}
 */
class SteamListsService
{
    /**
     * The tier list: each tier's games, in order.
     *
     * @return array<string, list<ListGame>>
     */
    public function tiers(User $user): array
    {
        $entries = SteamTierEntry::query()->where('user_id', $user->id)->orderBy('position')->orderBy('id')->get();
        $games = $this->libraryGames($user, array_values($entries->map(fn (SteamTierEntry $entry) => $entry->app_id)->all()));
        $tiers = array_fill_keys(Tier::values(), []);

        foreach ($entries as $entry) {
            $tiers[$entry->tier->value][] = $this->listGame($entry->app_id, $entry->name, $games->get($entry->app_id));
        }

        return $tiers;
    }

    /**
     * The tier of each game on the list.
     *
     * @return array<int, string> tier by app id
     */
    public function tierByApp(User $user): array
    {
        /** @var array<int, string> */
        return SteamTierEntry::query()->toBase()->where('user_id', $user->id)->pluck('tier', 'app_id')->all();
    }

    /**
     * Replace the whole tier list with the given order. Every game must be
     * in the user's library or already on the list.
     *
     * @param  array<string, list<int>>  $tiers  App ids by tier, in order.
     *
     * @throws ValidationException
     */
    public function setTiers(User $user, array $tiers): void
    {
        $appIds = array_merge(...array_values($tiers));

        if (count($appIds) !== count(array_unique($appIds))) {
            throw ValidationException::withMessages(['tiers' => 'Um jogo só pode estar em um tier.']);
        }

        $names = $this->namesOf($user, $appIds);

        if (count($names) !== count($appIds)) {
            throw ValidationException::withMessages(['tiers' => 'Algum jogo não está na sua biblioteca da Steam.']);
        }

        DB::transaction(function () use ($user, $tiers, $names) {
            $existing = SteamTierEntry::query()->where('user_id', $user->id)->get()->keyBy('app_id');
            $kept = [];

            foreach (Tier::cases() as $tier) {
                foreach ($tiers[$tier->value] ?? [] as $position => $appId) {
                    $kept[$appId] = true;
                    $entry = $existing->get($appId) ?? new SteamTierEntry(['user_id' => $user->id, 'app_id' => $appId]);
                    $entry->fill(['name' => $names[$appId], 'tier' => $tier, 'position' => $position])->save();
                }
            }

            $gone = $existing->keys()->reject(fn (int $appId) => isset($kept[$appId]));

            foreach ($gone->chunk(200) as $chunk) {
                SteamTierEntry::query()->where('user_id', $user->id)->whereIn('app_id', $chunk->all())->delete();
            }
        });
    }

    /**
     * Put a game at the end of a tier, or take it off the list (null). A
     * game already in that tier keeps its place.
     */
    public function setTier(User $user, SteamGame $game, ?Tier $tier): void
    {
        $entry = SteamTierEntry::query()->where('user_id', $user->id)->where('app_id', $game->app_id)->first();

        if ($tier === null) {
            $entry?->delete();

            return;
        }

        if ($entry?->tier === $tier) {
            return;
        }

        $last = SteamTierEntry::query()->where('user_id', $user->id)->where('tier', $tier->value)->max('position');

        SteamTierEntry::query()->updateOrCreate(
            ['user_id' => $user->id, 'app_id' => $game->app_id],
            ['name' => $game->name, 'tier' => $tier, 'position' => is_numeric($last) ? (int) $last + 1 : 0],
        );
    }

    /**
     * The "para zerar" list: games being played, then the queue in order,
     * then the finished ones, most recent first.
     *
     * @return Collection<int, SteamBacklogEntry>
     */
    public function backlog(User $user): Collection
    {
        $order = [BacklogStatus::Playing->value => 0, BacklogStatus::Queued->value => 1, BacklogStatus::Completed->value => 2];

        return SteamBacklogEntry::query()->where('user_id', $user->id)->get()
            ->sort(fn (SteamBacklogEntry $a, SteamBacklogEntry $b) => [
                $order[$a->status->value],
                $a->status === BacklogStatus::Queued ? $a->position : 0,
                -($a->completed_at ?? $a->started_at ?? $a->added_at)->getTimestamp(),
                $a->id,
            ] <=> [
                $order[$b->status->value],
                $b->status === BacklogStatus::Queued ? $b->position : 0,
                -($b->completed_at ?? $b->started_at ?? $b->added_at)->getTimestamp(),
                $b->id,
            ])
            ->values();
    }

    /**
     * The library game of each entry, for its playtime and page link.
     *
     * @param  list<int>  $appIds
     * @return Collection<int, SteamGame> by app id
     */
    public function libraryGames(User $user, array $appIds): Collection
    {
        $account = $user->steamAccount;

        if ($account === null || $appIds === []) {
            return collect();
        }

        return $account->games()->whereIn('app_id', $appIds)->get()->keyBy('app_id');
    }

    /**
     * Add a game at the end of the queue. A game already on the list stays
     * as it is.
     */
    public function addToBacklog(User $user, SteamGame $game): SteamBacklogEntry
    {
        $existing = SteamBacklogEntry::query()->where('user_id', $user->id)->where('app_id', $game->app_id)->first();

        if ($existing !== null) {
            return $existing;
        }

        return SteamBacklogEntry::query()->create([
            'user_id' => $user->id,
            'app_id' => $game->app_id,
            'name' => $game->name,
            'status' => BacklogStatus::Queued,
            'position' => $this->nextQueuePosition($user),
            'playtime_at_add' => $game->playtime_minutes,
            'added_at' => Date::now(),
        ]);
    }

    /**
     * Add several games at once, in the given order, at the end of the
     * queue; or, when they were already finished, straight to the finished
     * ones on the given day (today when none). Games already on the list
     * keep their place, except that marking them finished finishes them.
     *
     * @param  iterable<SteamGame>  $games
     * @return int how many games were added (or finished)
     */
    public function addManyToBacklog(User $user, iterable $games, bool $finished = false, ?CarbonImmutable $finishedOn = null): int
    {
        $at = match (true) {
            ! $finished => null,
            $finishedOn === null || $finishedOn->isToday() => Date::now(),
            default => $finishedOn->setTime(12, 0),
        };

        return DB::transaction(function () use ($user, $games, $at) {
            $changed = 0;

            foreach ($games as $game) {
                $entry = $this->addToBacklog($user, $game);

                if ($at !== null && $entry->status !== BacklogStatus::Completed) {
                    $this->setStatus($entry, BacklogStatus::Completed, $at);
                    $changed++;
                } elseif ($entry->wasRecentlyCreated) {
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /**
     * Move a game between waiting, playing and finished. Finishing records
     * the date (now, or the given moment) and the Steam playtime at that
     * moment; going back to the queue puts it at the end and forgets when it
     * was started or finished.
     */
    public function setStatus(SteamBacklogEntry $entry, BacklogStatus $status, ?CarbonImmutable $at = null): void
    {
        if ($entry->status === $status) {
            return;
        }

        $user = $entry->user;
        $now = $at ?? Date::now();

        $entry->fill(match ($status) {
            BacklogStatus::Queued => [
                'position' => $this->nextQueuePosition($user),
                'started_at' => null,
                'completed_at' => null,
                'playtime_at_completion' => null,
            ],
            BacklogStatus::Playing => [
                'started_at' => $entry->started_at ?? $now,
                'completed_at' => null,
                'playtime_at_completion' => null,
            ],
            BacklogStatus::Completed => [
                'started_at' => $entry->started_at ?? $now,
                'completed_at' => $now,
                'playtime_at_completion' => $this->libraryGames($user, [$entry->app_id])->get($entry->app_id)->playtime_minutes ?? null,
            ],
        });
        $entry->status = $status;
        $entry->save();
    }

    /**
     * Put the queue in the given order; queued games left out go after, in
     * their current order.
     *
     * @param  list<int>  $ids  Entry ids.
     */
    public function reorderQueue(User $user, array $ids): void
    {
        $queue = SteamBacklogEntry::query()->where('user_id', $user->id)->where('status', BacklogStatus::Queued->value)
            ->orderBy('position')->orderBy('id')->get()->keyBy('id');
        $ordered = collect($ids)->filter(fn (int $id) => $queue->has($id))->unique()
            ->merge($queue->keys()->reject(fn (int $id) => in_array($id, $ids, true)))
            ->values();

        DB::transaction(function () use ($ordered, $queue) {
            foreach ($ordered as $position => $id) {
                $entry = $queue->get($id);

                if ($entry !== null && $entry->position !== $position) {
                    $entry->forceFill(['position' => $position])->save();
                }
            }
        });
    }

    /**
     * What a list shows of a game: its name and, while it is in the library,
     * its page and playtime.
     *
     * @return ListGame
     */
    public function listGame(int $appId, string $name, ?SteamGame $game): array
    {
        return [
            'app_id' => $appId,
            'name' => $game->name ?? $name,
            'game_id' => $game?->id,
            'playtime_minutes' => $game?->playtime_minutes,
            'last_played_at' => $game?->last_played_at?->toIso8601String(),
        ];
    }

    private function nextQueuePosition(User $user): int
    {
        $last = SteamBacklogEntry::query()->where('user_id', $user->id)->where('status', BacklogStatus::Queued->value)->max('position');

        return is_numeric($last) ? (int) $last + 1 : 0;
    }

    /**
     * Names of the given games, from the library or the tier list.
     *
     * @param  list<int>  $appIds
     * @return array<int, string> by app id; unknown games are left out
     */
    private function namesOf(User $user, array $appIds): array
    {
        if ($appIds === []) {
            return [];
        }

        $names = [];

        foreach (array_chunk($appIds, 500) as $chunk) {
            $names += SteamTierEntry::query()->where('user_id', $user->id)->whereIn('app_id', $chunk)->pluck('name', 'app_id')->all();

            if ($user->steamAccount !== null) {
                $names = $user->steamAccount->games()->whereIn('app_id', $chunk)->pluck('name', 'app_id')->all() + $names;
            }
        }

        /** @var array<int, string> $names */
        return array_intersect_key($names, array_flip($appIds));
    }
}
