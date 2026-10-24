<?php

namespace Tests\Feature\Shows;

use App\Models\Show;
use App\Services\ShowPageParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ImportShowsCommandTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private const PAGE = <<<'HTML'
        <html><body><header><h1 class="title">shows</h1></header>
        <article class="markdown-body">
        <h1 id="a">Avenged Sevenfold | LIFE IS BUT A DREAM…</h1>
        <ul>
          <li>Data: 31/01/2026</li>
          <li>Local: Allianz Parque</li>
          <li>Setlist:
            <ol><li>Nightcall</li><li>Game Over</li></ol>
          </li>
        </ul>
        <h2 id="b">A Day To Remember | Show Abertura</h2>
        <ol><li>Sprach Zarathustra, Op. 30</li><li>I’m Made of Wax, Larry, What Are You Made Of?</li></ol>
        <h3 id="c">Mr. Bungle | Show Abertura</h3>
        <ol><li>Tuyo</li></ol>
        <h1 id="d">Roupa Nova</h1>
        <ul>
          <li>Data: 15/11/2025</li>
          <li>Local: Santos Convention Center</li>
          <li>Setlist:
            <ol><li>Seu jeito &amp; meu jeito</li></ol>
          </li>
        </ul>
        </article></body></html>
        HTML;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        Http::preventStrayRequests();
    }

    public function test_the_page_is_parsed_into_shows_and_opening_acts(): void
    {
        $shows = app(ShowPageParser::class)->parse(self::PAGE);

        $this->assertCount(2, $shows);
        $this->assertSame('Avenged Sevenfold', $shows[0]['band']);
        $this->assertSame('LIFE IS BUT A DREAM…', $shows[0]['tour']);
        $this->assertSame('2026-01-31', $shows[0]['show_date']);
        $this->assertSame('Allianz Parque', $shows[0]['venue']);
        $this->assertSame(['Nightcall', 'Game Over'], $shows[0]['setlist']);
        $this->assertSame([
            ['band' => 'A Day To Remember', 'setlist' => ['Sprach Zarathustra, Op. 30', 'I’m Made of Wax, Larry, What Are You Made Of?']],
            ['band' => 'Mr. Bungle', 'setlist' => ['Tuyo']],
        ], $shows[0]['opening_acts']);

        $this->assertNull($shows[1]['tour']);
        $this->assertSame(['Seu jeito & meu jeito'], $shows[1]['setlist']);
        $this->assertSame([], $shows[1]['opening_acts']);
    }

    public function test_shows_are_imported_into_the_only_household_once(): void
    {
        Http::fake(['brunobrasolin.github.io/*' => Http::response(self::PAGE)]);

        $this->artisan('shows:import')->assertSuccessful();

        $shows = Show::query()->where('household_id', $this->household->id)->orderBy('show_date')->get();
        $this->assertNull($shows[0]->created_by_user_id);
        $this->assertSame(['Roupa Nova', 'Avenged Sevenfold'], $shows->pluck('band')->all());
        $this->assertSame(['A Day To Remember', 'Mr. Bungle'], $shows[1]->openingActs->pluck('band')->all());

        $this->artisan('shows:import', ['household' => $this->household->id])
            ->expectsOutputToContain('2 já existiam')
            ->assertSuccessful();
        $this->assertSame(2, Show::query()->count());
    }

    public function test_dry_run_saves_nothing_and_local_files_are_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shows');
        file_put_contents($path, self::PAGE);

        $this->artisan('shows:import', ['--from' => $path, '--dry-run' => true])
            ->expectsOutputToContain('2 seriam importados')
            ->assertSuccessful();

        unlink($path);
        $this->assertSame(0, Show::query()->count());
    }

    public function test_the_household_is_chosen_when_there_are_several(): void
    {
        Http::fake(['*' => Http::response(self::PAGE)]);
        $outsider = $this->createOutsider();

        $this->artisan('shows:import')
            ->expectsChoice('Residência', (string) $outsider->householdId(), [$this->household->id => 'Casa Bruno & Letícia', $outsider->householdId() => 'Outra casa'])
            ->assertSuccessful();

        $this->assertSame(2, Show::query()->where('household_id', $outsider->householdId())->count());
        $this->assertSame(0, $this->household->shows()->count());
    }

    public function test_unknown_households_and_invalid_shows_fail(): void
    {
        $this->artisan('shows:import', ['household' => 999])->assertFailed();

        Http::fake(['*' => Http::response('<article><h1>Sem data</h1><ul><li>Local: X</li></ul></article>')]);

        $this->artisan('shows:import')->assertFailed();
        $this->assertSame(0, Show::query()->count());
    }
}
