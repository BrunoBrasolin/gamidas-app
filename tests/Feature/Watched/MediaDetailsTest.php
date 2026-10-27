<?php

namespace Tests\Feature\Watched;

use App\Models\WatchedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class MediaDetailsTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        Http::preventStrayRequests();
        config(['services.tmdb.token' => 'test-token', 'services.tmdb.language' => 'pt-BR']);
    }

    /**
     * @return array<string, mixed>
     */
    private function movie(): array
    {
        return [
            'id' => 157336,
            'title' => 'Interestelar',
            'original_title' => 'Interstellar',
            'tagline' => 'O fim da Terra não será o fim da humanidade.',
            'overview' => 'Exploradores viajam por um buraco de minhoca.',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'release_date' => '2014-11-05',
            'runtime' => 169,
            'genres' => [['id' => 12, 'name' => 'Aventura'], ['id' => 878, 'name' => 'Ficção científica']],
            'vote_average' => 8.46,
            'vote_count' => 30000,
            'credits' => [
                'cast' => [
                    ['name' => 'Matthew McConaughey', 'character' => 'Cooper', 'profile_path' => '/mm.jpg'],
                    ['name' => 'Anne Hathaway', 'character' => 'Brand', 'profile_path' => null],
                ],
                'crew' => [
                    ['name' => 'Christopher Nolan', 'job' => 'Director'],
                    ['name' => 'Hans Zimmer', 'job' => 'Original Music Composer'],
                ],
            ],
        ];
    }

    public function test_movies_are_found_by_their_tmdb_id_and_cached(): void
    {
        Http::fake(['api.themoviedb.org/3/movie/157336*' => Http::response($this->movie())]);
        $item = WatchedItem::factory()->for($this->household)->create(['title' => 'Interestelar', 'provider_ids' => ['tmdb' => '157336']]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('watched/show', false)
                ->where('tmdb_configured', true)
                ->where('item.id', $item->id)
                ->where('details.kind', 'movie')
                ->where('details.original_title', 'Interstellar')
                ->where('details.poster_url', 'https://image.tmdb.org/t/p/w342/poster.jpg')
                ->where('details.runtime_minutes', 169)
                ->where('details.genres', ['Aventura', 'Ficção científica'])
                ->where('details.rating', 8.5)
                ->where('details.creators', ['Christopher Nolan'])
                ->where('details.cast.0', ['name' => 'Matthew McConaughey', 'character' => 'Cooper', 'photo_url' => 'https://image.tmdb.org/t/p/w185/mm.jpg'])
                ->where('details.cast.1.photo_url', null)
                ->where('details.url', 'https://www.themoviedb.org/movie/157336'));

        $this->actingAs($this->leticia)->get(route('watched.show', $item))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['language'] === 'pt-BR'
            && $request['append_to_response'] === 'credits');
    }

    public function test_movies_without_ids_are_searched_by_title_and_year(): void
    {
        Http::fake([
            'api.themoviedb.org/3/search/movie*' => Http::response(['results' => [['id' => 157336]]]),
            'api.themoviedb.org/3/movie/157336*' => Http::response($this->movie()),
        ]);
        $item = WatchedItem::factory()->for($this->household)->create(['title' => 'Interestelar', 'year' => 2014, 'provider_ids' => null]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertInertia(fn (Assert $page) => $page->where('details.title', 'Interestelar'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search/movie') && $request['query'] === 'Interestelar' && $request['year'] === 2014);
    }

    public function test_episodes_are_found_through_their_imdb_id(): void
    {
        Http::fake([
            'api.themoviedb.org/3/find/tt2301451*' => Http::response(['tv_episode_results' => [['show_id' => 1396, 'season_number' => 5, 'episode_number' => 14]]]),
            'api.themoviedb.org/3/tv/1396/season/5/episode/14*' => Http::response(['name' => 'Ozymandias', 'overview' => 'Walt foge.', 'air_date' => '2013-09-15', 'runtime' => 48, 'still_path' => '/still.jpg', 'season_number' => 5, 'episode_number' => 14, 'vote_average' => 9.9, 'vote_count' => 100]),
            'api.themoviedb.org/3/tv/1396*' => Http::response(['name' => 'Breaking Bad', 'original_name' => 'Breaking Bad', 'number_of_seasons' => 5, 'first_air_date' => '2008-01-20', 'episode_run_time' => [47], 'created_by' => [['name' => 'Vince Gilligan']], 'credits' => ['cast' => [['name' => 'Bryan Cranston', 'character' => 'Walter White']]]]),
        ]);
        $item = WatchedItem::factory()->for($this->household)->episode('Breaking Bad', 5, 14)->create(['title' => 'Ozymandias', 'provider_ids' => ['imdb' => 'tt2301451']]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertInertia(fn (Assert $page) => $page
                ->where('details.kind', 'tv')
                ->where('details.title', 'Breaking Bad')
                ->where('details.seasons', 5)
                ->where('details.creators', ['Vince Gilligan'])
                ->where('details.cast.0.name', 'Bryan Cranston')
                ->where('details.episode.name', 'Ozymandias')
                ->where('details.episode.still_url', 'https://image.tmdb.org/t/p/w300/still.jpg')
                ->where('details.episode.rating', 9.9));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'find/tt2301451') && $request['external_source'] === 'imdb_id');
    }

    public function test_episodes_without_ids_are_found_by_searching_the_series(): void
    {
        Http::fake([
            'api.themoviedb.org/3/search/tv*' => Http::response(['results' => [['id' => 70523]]]),
            'api.themoviedb.org/3/tv/70523/season/1/episode/2*' => Http::response(['name' => 'Mentiras']),
            'api.themoviedb.org/3/tv/70523*' => Http::response(['name' => 'Dark']),
        ]);
        $item = WatchedItem::factory()->for($this->household)->episode('Dark', 1, 2)->create(['year' => 2017, 'provider_ids' => null]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertInertia(fn (Assert $page) => $page->where('details.title', 'Dark')->where('details.episode.name', 'Mentiras'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search/tv') && $request['query'] === 'Dark' && $request['first_air_date_year'] === 2017);
    }

    public function test_tmdb_failures_show_the_page_without_details_and_are_retried(): void
    {
        Http::fake(['api.themoviedb.org/*' => Http::response(['status_message' => 'Erro'], 500)]);
        $item = WatchedItem::factory()->for($this->household)->create(['provider_ids' => ['tmdb' => '157336']]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('details', null)->where('tmdb_configured', true));
        $this->actingAs($this->bruno)->get(route('watched.show', $item))->assertOk();

        Http::assertSentCount(2);
    }

    public function test_without_a_token_nothing_is_requested(): void
    {
        config(['services.tmdb.token' => null]);
        $item = WatchedItem::factory()->for($this->household)->create(['provider_ids' => ['tmdb' => '157336']]);

        $this->actingAs($this->bruno)
            ->get(route('watched.show', $item))
            ->assertInertia(fn (Assert $page) => $page->where('details', null)->where('tmdb_configured', false));

        Http::assertNothingSent();
    }

    public function test_items_of_other_households_are_not_found(): void
    {
        $outsider = $this->createOutsider();
        $theirs = WatchedItem::factory()->create(['household_id' => $outsider->householdId()]);

        $this->actingAs($this->bruno)->get(route('watched.show', $theirs))->assertNotFound();
        Http::assertNothingSent();
    }
}
