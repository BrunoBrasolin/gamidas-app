<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Show;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits shows. The opening acts are always replaced as a whole,
 * in the order they were sent.
 *
 * @phpstan-type OpeningAct array{band: string, setlist: list<string>}
 * @phpstan-type ShowData array{band: string, tour: string|null, venue: string, show_date: string, setlist: list<string>, photos_url: string|null, playlist_url: string|null, opening_acts: list<OpeningAct>}
 */
class ShowService
{
    /**
     * @param  ShowData  $data
     */
    public function create(Household $household, ?User $creator, array $data): Show
    {
        return DB::transaction(function () use ($household, $creator, $data) {
            $show = $household->shows()->create([...$this->attributes($data), 'created_by_user_id' => $creator?->id]);
            $this->syncOpeningActs($show, $data['opening_acts']);

            return $show;
        });
    }

    /**
     * @param  ShowData  $data
     */
    public function update(Show $show, array $data): Show
    {
        return DB::transaction(function () use ($show, $data) {
            $show->update($this->attributes($data));
            $this->syncOpeningActs($show, $data['opening_acts']);

            return $show;
        });
    }

    public function delete(Show $show): void
    {
        $show->delete();
    }

    /**
     * @param  ShowData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'band' => $data['band'],
            'tour' => $data['tour'],
            'venue' => $data['venue'],
            'show_date' => $data['show_date'],
            'setlist' => $data['setlist'],
            'photos_url' => $data['photos_url'],
            'playlist_url' => $data['playlist_url'],
        ];
    }

    /**
     * @param  list<OpeningAct>  $acts
     */
    private function syncOpeningActs(Show $show, array $acts): void
    {
        $show->openingActs()->delete();

        foreach ($acts as $position => $act) {
            $show->openingActs()->create([
                'position' => $position,
                'band' => $act['band'],
                'setlist' => $act['setlist'],
            ]);
        }
    }
}
