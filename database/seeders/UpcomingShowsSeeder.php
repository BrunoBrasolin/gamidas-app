<?php

namespace Database\Seeders;

/**
 * Adds the upcoming São Paulo shows the household has tickets for. Setlists
 * are empty until the show happens; fill them in from the show page.
 *
 * Shows the household already has (same band and date) are skipped, so it
 * can run again.
 *
 * Run with: php artisan db:seed --class=UpcomingShowsSeeder --force
 */
class UpcomingShowsSeeder extends ShowsSeeder
{
    public const SHOWS = [
        [
            'band' => 'Iron Maiden',
            'tour' => 'Run For Your Lives',
            'venue' => 'Nubank Parque, São Paulo',
            'show_date' => '2026-10-25',
            'setlist' => [],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [
                ['band' => 'Alter Bridge', 'setlist' => []],
            ],
        ],
        [
            'band' => 'Rush',
            'tour' => 'Fifty Something',
            'venue' => 'Nubank Parque, São Paulo',
            'show_date' => '2027-01-24',
            'setlist' => [],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [],
        ],
        [
            'band' => 'Foo Fighters',
            'tour' => 'Take Cover',
            'venue' => 'MorumBIS, São Paulo',
            'show_date' => '2027-02-20',
            'setlist' => [],
            'photos_url' => null,
            'playlist_url' => null,
            'opening_acts' => [
                ['band' => 'Karen Dió', 'setlist' => []],
                ['band' => 'Deb and The Mentals', 'setlist' => []],
            ],
        ],
    ];
}
