<?php

namespace Tests\Feature\Watched;

use App\Models\WatchedItem;
use App\Models\WatchProgress;
use App\Services\WatchHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class WatchProgressTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private const TICKS = 10_000_000;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-26 21:00:00');
        $this->createHousehold();
        $this->token = app(WatchHistoryService::class)->issueToken($this->household);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * An episode event, as the plugin sends it with "Send All Properties".
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function episode(string $event, int $positionSeconds, array $overrides = []): array
    {
        return [
            'NotificationType' => $event,
            'NotificationUsername' => 'bruno',
            'ItemId' => 'ep-514',
            'ItemType' => 'Episode',
            'Name' => 'Ozymandias',
            'SeriesName' => 'Breaking Bad',
            'SeasonNumber' => 5,
            'EpisodeNumber' => 14,
            'Year' => 2008,
            'RunTimeTicks' => 2_850 * self::TICKS,
            'PlaybackPositionTicks' => $positionSeconds * self::TICKS,
            'PlayedToCompletion' => false,
            'UtcTimestamp' => '2026-10-27T00:00:00Z',
            'Provider_imdb' => 'tt2301451',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): TestResponse
    {
        return $this->postJson(route('webhooks.jellyfin'), $payload, ['X-Gamidas-Token' => $this->token]);
    }

    public function test_starting_marks_the_item_as_playing(): void
    {
        $this->send($this->episode('PlaybackStart', 0))->assertJson(['status' => 'tracked']);

        $progress = WatchProgress::query()->sole();
        $this->assertSame([$this->household->id, 'bruno', 'Breaking Bad', 14], [$progress->household_id, $progress->viewer, $progress->series_name, $progress->episode_number]);
        $this->assertTrue($progress->isPlaying());
        $this->assertSame(['imdb' => 'tt2301451'], $progress->provider_ids);
    }

    public function test_stopping_midway_keeps_where_it_stopped(): void
    {
        $this->send($this->episode('PlaybackStart', 0));
        $this->send($this->episode('PlaybackStop', 1_250))->assertJson(['status' => 'tracked']);

        $progress = WatchProgress::query()->sole();
        $this->assertSame(1_250, $progress->position_seconds);
        $this->assertFalse($progress->isPlaying());
        $this->assertSame('2026-10-26 21:00:00', $progress->last_played_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('watched_items', 0);
    }

    public function test_finishing_records_it_and_forgets_where_it_stopped(): void
    {
        $this->send($this->episode('PlaybackStop', 1_250));
        $this->send($this->episode('PlaybackStop', 2_800, ['PlayedToCompletion' => true]))->assertJson(['status' => 'recorded']);

        $this->assertDatabaseCount('watch_progress', 0);
        $this->assertSame(['imdb' => 'tt2301451'], WatchedItem::query()->sole()->provider_ids);
    }

    public function test_barely_started_items_are_forgotten_but_earlier_progress_stays(): void
    {
        $this->send($this->episode('PlaybackStart', 0));
        $this->send($this->episode('PlaybackStop', 20))->assertJson(['status' => 'ignored']);
        $this->assertDatabaseCount('watch_progress', 0);

        $this->send($this->episode('PlaybackStop', 1_250));
        $this->send($this->episode('PlaybackStart', 1_250));
        $this->send($this->episode('PlaybackStop', 30))->assertJson(['status' => 'ignored']);

        $progress = WatchProgress::query()->sole();
        $this->assertSame(1_250, $progress->position_seconds);
        $this->assertFalse($progress->isPlaying());
    }

    public function test_each_person_keeps_their_own_place(): void
    {
        $this->send($this->episode('PlaybackStop', 1_250));
        $this->send($this->episode('PlaybackStop', 300, ['NotificationUsername' => 'leticia']));

        $this->assertSame(
            ['bruno' => 1_250, 'leticia' => 300],
            WatchProgress::query()->orderBy('viewer')->pluck('position_seconds', 'viewer')->all(),
        );
    }

    public function test_a_lost_stop_event_does_not_leave_it_playing_forever(): void
    {
        $this->send($this->episode('PlaybackStart', 0));

        Date::setTestNow('2026-10-27 03:00:00');

        $this->assertFalse(WatchProgress::query()->sole()->isPlaying());
    }

    public function test_the_page_lists_what_is_in_progress_and_the_series_being_watched(): void
    {
        $this->send($this->episode('PlaybackStart', 0, ['NotificationUsername' => 'leticia']));
        WatchedItem::factory()->for($this->household)->episode('Breaking Bad', 5, 13)->create(['title' => 'To’hajiilee', 'watched_at' => '2026-10-25 22:00:00', 'watched_on' => '2026-10-25']);
        WatchedItem::factory()->for($this->household)->episode('breaking bad', 5, 12)->create(['watched_at' => '2026-10-24 22:00:00', 'watched_on' => '2026-10-24']);
        WatchedItem::factory()->for($this->household)->episode('Dark', 1, 1)->create(['watched_at' => '2026-01-10 22:00:00', 'watched_on' => '2026-01-10']);
        WatchedItem::factory()->for($this->household)->create();

        $this->actingAs($this->bruno)
            ->get(route('watched.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('watched/index', false)
                ->has('in_progress', 1)
                ->where('in_progress.0.viewer', 'leticia')
                ->where('in_progress.0.playing', true)
                ->where('in_progress.0.position_seconds', 0)
                // Dark was last watched more than 6 months ago.
                ->has('series', 1)
                ->where('series.0.series_name', 'Breaking Bad')
                ->where('series.0.episode_number', 13));
    }

    public function test_items_in_progress_can_be_opened_and_removed_by_the_household_only(): void
    {
        $this->send($this->episode('PlaybackStop', 1_250));
        $progress = WatchProgress::query()->sole();

        $outsider = $this->createOutsider();
        $this->actingAs($outsider)->get(route('watched.progress.show', $progress))->assertNotFound();
        $this->actingAs($outsider)->delete(route('watched.progress.destroy', $progress))->assertNotFound();

        $this->actingAs($this->leticia)
            ->get(route('watched.progress.show', $progress))
            ->assertInertia(fn (Assert $page) => $page
                ->component('watched/show', false)
                ->where('item', null)
                ->where('progress.position_seconds', 1_250)
                ->where('details', null)
                ->where('tmdb_configured', false));

        $this->actingAs($this->leticia)
            ->delete(route('watched.progress.destroy', $progress))
            ->assertRedirect(route('watched.index'));
        $this->assertModelMissing($progress);
    }
}
