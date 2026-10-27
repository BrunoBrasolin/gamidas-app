<?php

namespace Tests\Feature\Shows;

use App\Models\Show;
use Database\Seeders\IronMaidenSetlistSeeder;
use Database\Seeders\NubankParqueSeeder;
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

    public function test_it_fills_in_the_iron_maiden_setlists_keeping_the_rest(): void
    {
        $this->createHousehold();
        app(UpcomingShowsSeeder::class)->run();

        $maiden = Show::query()->where('band', 'Iron Maiden')->sole();
        $maiden->update(['photos_url' => 'https://photos.example.com/maiden']);

        app(IronMaidenSetlistSeeder::class)->run();
        app(IronMaidenSetlistSeeder::class)->run();

        $maiden = Show::query()->where('band', 'Iron Maiden')->sole();
        $this->assertSame(IronMaidenSetlistSeeder::SETLIST, $maiden->setlist);
        $this->assertSame('Wasted Years', $maiden->setlist[16]);
        $this->assertSame('https://photos.example.com/maiden', $maiden->photos_url);
        $this->assertSame('Nubank Parque, São Paulo', $maiden->venue);

        $this->assertSame(['Alter Bridge'], $maiden->openingActs->pluck('band')->all());
        $this->assertSame(IronMaidenSetlistSeeder::OPENING_ACT_SETLIST, $maiden->openingActs[0]->setlist);
    }

    public function test_it_renames_allianz_parque_to_nubank_parque_since_the_new_name(): void
    {
        $this->createHousehold();
        $older = Show::factory()->for($this->household)->create(['venue' => 'Allianz Parque', 'show_date' => '2024-12-06']);
        $maiden = Show::factory()->for($this->household)->create(['venue' => 'Allianz Parque, São Paulo', 'show_date' => '2026-10-25']);
        $other = Show::factory()->for($this->household)->create(['venue' => 'MorumBIS, São Paulo', 'show_date' => '2026-11-01']);

        app(NubankParqueSeeder::class)->run();
        app(NubankParqueSeeder::class)->run();

        $this->assertSame('Allianz Parque', $older->fresh()?->venue);
        $this->assertSame('Nubank Parque, São Paulo', $maiden->fresh()?->venue);
        $this->assertSame('MorumBIS, São Paulo', $other->fresh()?->venue);
    }

    public function test_it_creates_the_iron_maiden_show_when_missing(): void
    {
        $this->createHousehold();

        app(IronMaidenSetlistSeeder::class)->run();

        $maiden = Show::query()->where('household_id', $this->household->id)->sole();
        $this->assertSame('2026-10-25', $maiden->show_date->toDateString());
        $this->assertSame('Murders in the Rue Morgue', $maiden->setlist[0]);
        $this->assertSame('Isolation', $maiden->openingActs->sole()->setlist[7]);
    }
}
