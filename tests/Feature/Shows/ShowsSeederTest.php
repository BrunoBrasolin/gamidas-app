<?php

namespace Tests\Feature\Shows;

use App\Models\Show;
use Database\Seeders\ShowsSeeder;
use Database\Seeders\UpcomingShowsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ShowsSeederTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    public function test_it_adds_the_shows_once(): void
    {
        $this->createHousehold();

        app(ShowsSeeder::class)->run();
        app(ShowsSeeder::class)->run();

        $this->assertSame(4, Show::query()->where('household_id', $this->household->id)->count());

        $acdc = Show::query()->where('band', 'AC/DC')->sole();
        $this->assertSame('2026-02-24', $acdc->show_date->toDateString());
        $this->assertSame('For Those About to Rock (We Salute You)', $acdc->setlist[20]);
        $this->assertSame(['The Pretty Reckless'], $acdc->openingActs->pluck('band')->all());
        $this->assertNull($acdc->created_by_user_id);
    }

    public function test_it_adds_the_upcoming_shows_once(): void
    {
        $this->createHousehold();

        app(UpcomingShowsSeeder::class)->run();
        app(UpcomingShowsSeeder::class)->run();

        $this->assertSame(
            ['2026-10-25 Iron Maiden', '2027-01-24 Rush', '2027-02-20 Foo Fighters'],
            Show::query()->where('household_id', $this->household->id)->orderBy('show_date')->get()
                ->map(fn (Show $show) => "{$show->show_date->toDateString()} {$show->band}")->all(),
        );

        $maiden = Show::query()->where('band', 'Iron Maiden')->sole();
        $this->assertSame([], $maiden->setlist);
        $this->assertSame(['Alter Bridge'], $maiden->openingActs->pluck('band')->all());
    }
}
