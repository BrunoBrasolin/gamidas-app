<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\Show;
use Illuminate\Database\Seeder;

/**
 * Renames "Allianz Parque" to "Nubank Parque" in the venue of the shows
 * since the stadium took the new name (May 2026). Older shows keep the name
 * the stadium had when they happened.
 *
 * Shows already renamed are left alone, so it can run again.
 *
 * Run with: php artisan db:seed --class=NubankParqueSeeder --force
 */
class NubankParqueSeeder extends Seeder
{
    public const OLD_NAME = 'Allianz Parque';

    public const NEW_NAME = 'Nubank Parque';

    public const RENAMED_ON = '2026-05-04';

    public function run(): void
    {
        $household = Household::query()->sole();

        $shows = $household->shows()
            ->where('venue', 'like', '%'.self::OLD_NAME.'%')
            ->whereDate('show_date', '>=', self::RENAMED_ON)
            ->get();

        $shows->each(function (Show $show) {
            $show->update(['venue' => str_replace(self::OLD_NAME, self::NEW_NAME, $show->venue)]);
            $this->command?->line("{$show->band} — {$show->show_date->toDateString()}: {$show->venue}");
        });

        $this->command?->info($shows->count().' show(s) renomeado(s) para '.self::NEW_NAME);
    }
}
