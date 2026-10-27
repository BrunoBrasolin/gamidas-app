<?php

namespace Tests\Feature\Watched;

use App\Models\JellyfinConnection;
use App\Models\WatchedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class WatchedItemTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-26 12:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('watched.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_households_items_of_the_year_for_every_member(): void
    {
        WatchedItem::factory()->for($this->household)->create(['title' => 'Interestelar', 'runtime_seconds' => 10_164]);
        WatchedItem::factory()->for($this->household)->episode('Breaking Bad', 5, 14)->create(['watched_at' => '2026-10-21 22:00:00', 'watched_on' => '2026-10-21']);
        WatchedItem::factory()->for($this->household)->episode('breaking bad', 5, 15)->create(['viewer' => 'leticia', 'watched_at' => '2026-10-22 22:00:00', 'watched_on' => '2026-10-22']);
        WatchedItem::factory()->for($this->household)->create(['watched_at' => '2024-03-01 20:00:00', 'watched_on' => '2024-03-01']);
        $outsider = $this->createOutsider();
        WatchedItem::factory()->create(['household_id' => $outsider->householdId()]);

        $this->actingAs($this->leticia)
            ->get(route('watched.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('watched/index', false)
                ->where('year', 2026)
                ->where('years', [2026, 2025, 2024])
                ->has('items', 3)
                ->where('items.0.series_name', 'breaking bad')
                ->where('items.0.viewer', 'leticia')
                ->where('items.0.episode_number', 15)
                ->where('items.2.title', 'Interestelar')
                ->where('stats', ['items' => 3, 'movies' => 1, 'episodes' => 2, 'series' => 1, 'seconds' => 15_564])
                ->where('jellyfin.connected', false)
                ->where('jellyfin.new_token', null)
                ->where('jellyfin.webhook_url', route('webhooks.jellyfin')));

        $this->actingAs($this->bruno)
            ->get(route('watched.index', ['year' => 2024]))
            ->assertInertia(fn (Assert $page) => $page->where('year', 2024)->has('items', 1));

        // A year outside the history falls back to the newest one.
        $this->actingAs($this->bruno)
            ->get(route('watched.index', ['year' => 1999]))
            ->assertInertia(fn (Assert $page) => $page->where('year', 2026));
    }

    public function test_a_token_is_issued_shown_once_and_stored_hashed(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('watched.jellyfin.store'))
            ->assertRedirect(route('watched.index'));

        $token = null;
        $this->actingAs($this->bruno)
            ->get(route('watched.index'))
            ->assertInertia(function (Assert $page) use (&$token) {
                $page->where('jellyfin.connected', true);
                $token = $page->toArray()['props']['jellyfin']['new_token'];
            });

        $this->assertIsString($token);
        $this->assertSame(48, strlen($token));
        $connection = JellyfinConnection::query()->sole();
        $this->assertSame($this->household->id, $connection->household_id);
        $this->assertSame(hash('sha256', $token), $connection->token_hash);

        // The connection is the household's: the other member sees it too.
        $this->actingAs($this->leticia)
            ->get(route('watched.index'))
            ->assertInertia(fn (Assert $page) => $page->where('jellyfin.connected', true)->where('jellyfin.new_token', null));
    }

    public function test_any_member_can_disconnect_and_the_history_stays(): void
    {
        $this->actingAs($this->bruno)->post(route('watched.jellyfin.store'));
        WatchedItem::factory()->for($this->household)->create();

        $this->actingAs($this->leticia)
            ->delete(route('watched.jellyfin.destroy'))
            ->assertRedirect(route('watched.index'));

        $this->assertDatabaseCount('jellyfin_connections', 0);
        $this->assertDatabaseCount('watched_items', 1);
    }

    public function test_items_can_be_removed_by_any_member_of_their_household(): void
    {
        $ours = WatchedItem::factory()->for($this->household)->create(['viewer' => 'bruno']);
        $theirs = WatchedItem::factory()->create(['household_id' => $this->createOutsider()->householdId()]);

        $this->actingAs($this->bruno)->delete(route('watched.destroy', $theirs))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('watched.destroy', $ours))->assertRedirect();

        $this->assertModelMissing($ours);
        $this->assertModelExists($theirs);
    }
}
