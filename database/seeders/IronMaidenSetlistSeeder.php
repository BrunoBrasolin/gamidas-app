<?php

namespace Database\Seeders;

use App\Http\Requests\Shows\ShowRequest;
use App\Models\Household;
use App\Models\Show;
use App\Models\ShowOpeningAct;
use App\Services\ShowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

/**
 * Fills in the setlists of the Iron Maiden show of 2026-10-25 and of its
 * opening act, Alter Bridge, as reported by Rolling Stone Brasil (intro tape
 * left out).
 *
 * The show added by {@see UpcomingShowsSeeder} only gets its setlists
 * replaced; venue, links and the other opening acts are kept as they are. If
 * the household doesn't have the show yet, it is created. Can run again.
 *
 * Run with: php artisan db:seed --class=IronMaidenSetlistSeeder --force
 */
class IronMaidenSetlistSeeder extends Seeder
{
    public const BAND = 'Iron Maiden';

    public const SHOW_DATE = '2026-10-25';

    public const SETLIST = [
        'Murders in the Rue Morgue',
        'Wrathchild',
        'Killers',
        'Phantom of the Opera',
        'The Number of the Beast',
        'Infinite Dreams',
        'Powerslave',
        '2 Minutes to Midnight',
        'Rime of the Ancient Mariner',
        'Run to the Hills',
        'Seventh Son of a Seventh Son',
        'The Trooper',
        'Hallowed Be Thy Name',
        'Iron Maiden',
        'Aces High',
        'Fear of the Dark',
        'Wasted Years',
    ];

    public const OPENING_ACT = 'Alter Bridge';

    public const OPENING_ACT_SETLIST = [
        'Silent Divide',
        'Addicted to Pain',
        'Cry of Achilles',
        'Fortress',
        'Silver Tongue',
        'Metalingus',
        'Blackbird',
        'Isolation',
    ];

    public function __construct(private readonly ShowService $shows)
    {
        //
    }

    public function run(): void
    {
        $household = Household::query()->sole();
        $label = self::BAND.' — '.self::SHOW_DATE;

        $show = $household->shows()
            ->where('band', self::BAND)
            ->whereDate('show_date', self::SHOW_DATE)
            ->first();

        $data = $show === null ? $this->newShow() : $this->existingShow($show);
        $data['setlist'] = self::SETLIST;
        $data['opening_acts'] = $this->withOpeningActSetlist($data['opening_acts']);

        Validator::make($data, (new ShowRequest)->rules())->validate();

        if ($show === null) {
            $this->shows->create($household, null, $data);
        } else {
            $this->shows->update($show, $data);
        }

        $this->command?->info("{$label}: ".count(self::SETLIST).' músicas, '
            .self::OPENING_ACT.': '.count(self::OPENING_ACT_SETLIST).' músicas');
    }

    /**
     * @return array{band: string, tour: string|null, venue: string, show_date: string, setlist: list<string>, photos_url: string|null, playlist_url: string|null, opening_acts: list<array{band: string, setlist: list<string>}>}
     */
    private function newShow(): array
    {
        return collect(UpcomingShowsSeeder::SHOWS)
            ->first(fn (array $show) => $show['band'] === self::BAND && $show['show_date'] === self::SHOW_DATE);
    }

    /**
     * @return array{band: string, tour: string|null, venue: string, show_date: string, setlist: list<string>, photos_url: string|null, playlist_url: string|null, opening_acts: list<array{band: string, setlist: list<string>}>}
     */
    private function existingShow(Show $show): array
    {
        return [
            'band' => $show->band,
            'tour' => $show->tour,
            'venue' => $show->venue,
            'show_date' => $show->show_date->toDateString(),
            'setlist' => $show->setlist,
            'photos_url' => $show->photos_url,
            'playlist_url' => $show->playlist_url,
            'opening_acts' => array_values($show->openingActs
                ->map(fn (ShowOpeningAct $act) => ['band' => $act->band, 'setlist' => $act->setlist])
                ->all()),
        ];
    }

    /**
     * Set the opening act's setlist, adding the act at the end if it's missing.
     *
     * @param  list<array{band: string, setlist: list<string>}>  $acts
     * @return list<array{band: string, setlist: list<string>}>
     */
    private function withOpeningActSetlist(array $acts): array
    {
        $found = false;

        foreach ($acts as &$act) {
            if (mb_strtolower($act['band']) === mb_strtolower(self::OPENING_ACT)) {
                $act['setlist'] = self::OPENING_ACT_SETLIST;
                $found = true;
            }
        }
        unset($act);

        if (! $found) {
            $acts[] = ['band' => self::OPENING_ACT, 'setlist' => self::OPENING_ACT_SETLIST];
        }

        return $acts;
    }
}
