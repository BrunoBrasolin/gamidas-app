<?php

namespace Tests\Feature\Watched;

use App\Enums\WatchType;
use App\Models\Household;
use App\Models\JellyfinConnection;
use App\Models\WatchedItem;
use App\Services\WatchHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class JellyfinWebhookTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-26 12:00:00');
        $this->createHousehold();
        $this->token = app(WatchHistoryService::class)->issueToken($this->household);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * A completed movie, as sent by the plugin with "Send All Properties".
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function movie(array $overrides = []): array
    {
        return [
            'NotificationType' => 'PlaybackStop',
            'NotificationUsername' => 'bruno',
            'ItemId' => 'a1b2c3d4e5f6',
            'ItemType' => 'Movie',
            'Name' => 'Interestelar',
            'Year' => 2014,
            'RunTimeTicks' => 101_640_000_000,
            'PlayedToCompletion' => true,
            'UtcTimestamp' => '2026-10-21T01:30:00.0000000Z',
            'Provider_tmdb' => '157336',
            'Provider_imdb' => 'tt0816692',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload, ?string $token = null): TestResponse
    {
        return $this->postJson(route('webhooks.jellyfin'), $payload, ['X-Gamidas-Token' => $token ?? $this->token]);
    }

    public function test_a_completed_movie_is_recorded_for_the_household(): void
    {
        $this->send($this->movie())->assertOk()->assertExactJson(['status' => 'recorded']);

        $item = WatchedItem::query()->sole();
        $this->assertSame($this->household->id, $item->household_id);
        $this->assertSame('bruno', $item->viewer);
        $this->assertSame(WatchType::Movie, $item->type);
        $this->assertSame('Interestelar', $item->title);
        $this->assertSame(2014, $item->year);
        $this->assertSame(10_164, $item->runtime_seconds);
        $this->assertNull($item->series_name);
        $this->assertSame(['imdb' => 'tt0816692', 'tmdb' => '157336'], $item->provider_ids);
        // 01:30 UTC is still the previous evening in São Paulo.
        $this->assertSame('2026-10-20 22:30:00', $item->watched_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20', $item->watched_on->toDateString());
        $this->assertNotNull($this->household->jellyfinConnection?->last_received_at);
    }

    public function test_a_bearer_token_is_accepted_too(): void
    {
        $this->postJson(route('webhooks.jellyfin'), $this->movie(), ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertJson(['status' => 'recorded']);
    }

    public function test_missing_or_unknown_tokens_are_rejected(): void
    {
        $this->postJson(route('webhooks.jellyfin'), $this->movie())->assertUnauthorized();
        $this->send($this->movie(), 'not-a-token')->assertUnauthorized();

        $this->assertDatabaseCount('watched_items', 0);
    }

    public function test_issuing_a_new_token_disables_the_previous_one(): void
    {
        $new = app(WatchHistoryService::class)->issueToken($this->household);

        $this->send($this->movie())->assertUnauthorized();
        $this->send($this->movie(), $new)->assertOk();
        $this->assertSame(1, JellyfinConnection::query()->count());
    }

    public function test_episodes_keep_series_season_and_number_even_as_strings(): void
    {
        $this->send($this->movie([
            'ItemId' => 'ep-1',
            'ItemType' => 'Episode',
            'Name' => 'Ozymandias',
            'SeriesName' => 'Breaking Bad',
            'SeasonNumber' => '5',
            'EpisodeNumber' => '14',
            'PlayedToCompletion' => 'True',
            'RunTimeTicks' => null,
            'RunTime' => '00:47:30',
        ]))->assertOk();

        $item = WatchedItem::query()->sole();
        $this->assertSame(WatchType::Episode, $item->type);
        $this->assertSame('Breaking Bad', $item->series_name);
        $this->assertSame(5, $item->season_number);
        $this->assertSame(14, $item->episode_number);
        $this->assertSame(2_850, $item->runtime_seconds);
    }

    public function test_an_item_counts_once_per_day(): void
    {
        $this->send($this->movie())->assertJson(['status' => 'recorded']);
        $this->send($this->movie(['UtcTimestamp' => '2026-10-21T02:00:00Z']))->assertJson(['status' => 'duplicate']);
        $this->send($this->movie(['UtcTimestamp' => '2026-10-22T01:30:00Z']))->assertJson(['status' => 'recorded']);

        $this->assertSame(['2026-10-20', '2026-10-21'], WatchedItem::query()->orderBy('watched_on')->get()
            ->map(fn (WatchedItem $item) => $item->watched_on->toDateString())->all());
    }

    public function test_every_member_counts_and_an_item_watched_by_both_on_a_day_counts_once(): void
    {
        $this->send($this->movie())->assertJson(['status' => 'recorded']);
        $this->send($this->movie(['NotificationUsername' => 'leticia', 'UtcTimestamp' => '2026-10-21T02:30:00Z']))->assertJson(['status' => 'duplicate']);
        $this->send($this->movie(['NotificationUsername' => 'leticia', 'ItemId' => 'outro', 'Name' => 'Duna']))->assertJson(['status' => 'recorded']);

        $this->assertSame(['bruno', 'leticia'], WatchedItem::query()->orderBy('id')->pluck('viewer')->all());
        $this->assertSame([$this->household->id], WatchedItem::query()->distinct()->pluck('household_id')->all());
    }

    public function test_tokens_of_other_households_record_there(): void
    {
        $outsider = $this->createOutsider();
        /** @var Household $other */
        $other = $outsider->household();
        $theirs = app(WatchHistoryService::class)->issueToken($other);

        $this->send($this->movie(), $theirs)->assertJson(['status' => 'recorded']);

        $this->assertSame($other->id, WatchedItem::query()->sole()->household_id);
        $this->assertSame(0, $this->household->watchedItems()->count());
    }

    public function test_barely_started_playbacks_other_events_and_music_are_ignored(): void
    {
        $this->send($this->movie(['PlayedToCompletion' => false]))->assertJson(['status' => 'ignored']);
        $this->send($this->movie(['NotificationType' => 'PlaybackProgress']))->assertJson(['status' => 'ignored']);
        $this->send($this->movie(['ItemType' => 'Audio']))->assertJson(['status' => 'ignored']);

        $this->assertDatabaseCount('watched_items', 0);
        $this->assertNotNull($this->household->jellyfinConnection?->last_received_at);
    }

    public function test_payloads_that_are_not_jellyfin_events_are_rejected(): void
    {
        $this->send(['text' => 'Bruno terminou Interestelar'])->assertUnprocessable();
        $this->send($this->movie(['Name' => '']))->assertUnprocessable();

        $this->assertDatabaseCount('watched_items', 0);
    }

    public function test_timestamps_in_the_future_or_unreadable_fall_back_to_now(): void
    {
        $this->send($this->movie(['UtcTimestamp' => '2030-01-01T00:00:00Z']))->assertOk();
        $this->send($this->movie(['ItemId' => 'other', 'UtcTimestamp' => 'ontem']))->assertOk();

        $this->assertSame(
            ['2026-10-26 12:00:00', '2026-10-26 12:00:00'],
            WatchedItem::query()->pluck('watched_at')->map(fn ($at) => $at->format('Y-m-d H:i:s'))->all(),
        );
    }
}
