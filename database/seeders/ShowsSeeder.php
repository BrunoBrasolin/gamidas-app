<?php

namespace Database\Seeders;

use App\Http\Requests\Shows\ShowRequest;
use App\Models\Household;
use App\Services\ShowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

/**
 * Adds the 2026 São Paulo shows, with setlists taken from setlist.fm (songs
 * played from tape and solos left out).
 *
 * Shows the household already has (same band and date) are skipped, so it
 * can run again.
 *
 * Run with: php artisan db:seed --class=ShowsSeeder --force
 */
class ShowsSeeder extends Seeder
{
    public const SHOWS = [
        [
            'band' => 'AC/DC',
            'tour' => 'Power Up',
            'venue' => 'MorumBIS, São Paulo',
            'show_date' => '2026-02-24',
            'setlist' => [
                "If You Want Blood (You've Got It)",
                'Back in Black',
                'Demon Fire',
                'Shot Down in Flames',
                'Thunderstruck',
                'Have a Drink on Me',
                'Hells Bells',
                'Shot in the Dark',
                'Stiff Upper Lip',
                'Highway to Hell',
                'Shoot to Thrill',
                'Sin City',
                'Jailbreak',
                'Dirty Deeds Done Dirt Cheap',
                'High Voltage',
                'Riff Raff',
                'You Shook Me All Night Long',
                'Whole Lotta Rosie',
                'Let There Be Rock',
                'T.N.T.',
                'For Those About to Rock (We Salute You)',
            ],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [
                [
                    'band' => 'The Pretty Reckless',
                    'setlist' => [
                        'Death by Rock and Roll',
                        "Since You're Gone",
                        'Follow Me Down',
                        'Only Love Can Save Me Now',
                        'For I Am Death',
                        'Witches Burn',
                        'Make Me Wanna Die',
                        'Going to Hell',
                        'Heaven Knows',
                        'Take Me Down',
                    ],
                ],
            ],
        ],
        [
            'band' => 'Angra',
            'tour' => 'Angra Reunion — Bangers Open Air 2026',
            'venue' => 'Memorial da América Latina, São Paulo',
            'show_date' => '2026-04-26',
            'setlist' => [
                'Nothing to Say',
                'Angels Cry',
                'Tide of Changes - Part I',
                'Tide of Changes - Part II',
                'Lisbon',
                'Vida Seca',
                'Wuthering Heights',
                'Carolina IV',
                'Nova Era',
                'Waiting Silence',
                'Millennium Sun',
                'Heroes of Sand',
                'Ego Painted Grey',
                'Bleeding Heart',
                'Spread Your Fire',
                'Acid Rain',
                'Rebirth',
                'Silence and Distance',
                'Late Redemption',
                'Carry On',
            ],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [],
        ],
        [
            'band' => 'Angra',
            'tour' => 'Angra Reunion',
            'venue' => 'Espaço Unimed, São Paulo',
            'show_date' => '2026-04-29',
            'setlist' => [
                'Nothing to Say',
                'Angels Cry',
                'Tide of Changes - Part I',
                'Tide of Changes - Part II',
                'Lisbon',
                'Vida Seca',
                'Wuthering Heights',
                'Carolina IV',
                'Make Believe',
                'Waiting Silence',
                'Nova Era',
                'Millennium Sun',
                'Acid Rain',
                'Heroes of Sand',
                'Unholy Wars',
                'Rebirth',
                'Judgement Day',
                'Running Alone',
                'Bleeding Heart',
                'Ego Painted Grey',
                'Spread Your Fire',
                'Reaching Horizons',
                'Silence and Distance',
                'Late Redemption',
                'Carry On',
            ],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [],
        ],
        [
            'band' => 'Megadeth',
            'tour' => 'This Was Our Life',
            'venue' => 'Espaço Unimed, São Paulo',
            'show_date' => '2026-05-02',
            'setlist' => [
                'Tipping Point',
                'The Conjuring',
                'Hangar 18',
                'She-Wolf',
                'Sweating Bullets',
                "I Don't Care",
                'Dread and the Fugitive Mind',
                'Wake Up Dead',
                'In My Darkest Hour',
                'Hook in Mouth',
                'Let There Be Shred',
                'Symphony of Destruction',
                'Tornado of Souls',
                'Mechanix',
                'Ride the Lightning',
                'Peace Sells',
                'Holy Wars... The Punishment Due',
            ],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [],
        ],
    ];

    public function __construct(private readonly ShowService $shows)
    {
        //
    }

    public function run(): void
    {
        $household = Household::query()->sole();

        foreach (static::SHOWS as $show) {
            $label = "{$show['band']} — {$show['show_date']}";

            $exists = $household->shows()
                ->where('band', $show['band'])
                ->whereDate('show_date', $show['show_date'])
                ->exists();

            if ($exists) {
                $this->command?->line("{$label}: já existe");

                continue;
            }

            Validator::make($show, (new ShowRequest)->rules())->validate();
            $this->shows->create($household, null, $show);

            $this->command?->info("{$label}: ".count($show['setlist']).' músicas');
        }
    }
}
