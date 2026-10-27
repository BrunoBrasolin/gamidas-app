<?php

namespace App\Services\Tmdb;

use App\Enums\WatchType;
use App\Models\WatchedItem;
use App\Models\WatchProgress;
use Illuminate\Support\Facades\Cache;

/**
 * Poster, synopsis, genres and cast of a watched item, from TMDB. The item is
 * found by the ids Jellyfin sent (tmdb or imdb for movies; imdb or tvdb of
 * the episode, which TMDB maps to its series) or else by searching its title
 * and year. Lookups are cached for a month and details for a week; failures
 * are not cached, so a later visit tries again.
 *
 * @phpstan-type Person array{name: string, character: string|null, photo_url: string|null}
 * @phpstan-type Episode array{name: string|null, overview: string|null, date: string|null, runtime_minutes: int|null, still_url: string|null, season_number: int|null, episode_number: int|null, rating: float|null}
 * @phpstan-type Details array{kind: string, url: string, title: string|null, original_title: string|null, tagline: string|null, overview: string|null, poster_url: string|null, backdrop_url: string|null, date: string|null, runtime_minutes: int|null, genres: list<string>, rating: float|null, creators: list<string>, seasons: int|null, cast: list<Person>, episode: Episode|null}
 */
class MediaDetails
{
    private const CAST_SIZE = 12;

    /** Cached "searched and not found" marker. */
    private const NOT_FOUND = 0;

    public function __construct(private readonly TmdbClient $tmdb)
    {
        //
    }

    /**
     * @return Details|null
     */
    public function for(WatchedItem|WatchProgress $entry): ?array
    {
        if (! $this->tmdb->configured()) {
            return null;
        }

        return match ($entry->type) {
            WatchType::Movie => $this->movie($entry),
            WatchType::Episode => $this->episode($entry),
            WatchType::Video => null,
        };
    }

    /**
     * @return Details|null
     */
    private function movie(WatchedItem|WatchProgress $entry): ?array
    {
        $ids = $entry->provider_ids ?? [];
        $key = 'tmdb:find-movie:'.sha1(json_encode([$ids, $entry->title, $entry->year]) ?: '');
        $id = Cache::remember($key, now()->addMonth(), fn () => $this->findMovie($ids, $entry->title, $entry->year));

        if (! is_int($id) || $id === self::NOT_FOUND) {
            return null;
        }

        $movie = $this->cached("movie/{$id}", ['append_to_response' => 'credits']);

        return $movie === null ? null : $this->presentMovie($id, $movie);
    }

    /**
     * @param  array<string, string>  $ids
     */
    private function findMovie(array $ids, string $title, ?int $year): ?int
    {
        if (ctype_digit($ids['tmdb'] ?? '')) {
            return (int) $ids['tmdb'];
        }

        if (isset($ids['imdb'])) {
            $found = $this->tmdb->get('find/'.rawurlencode($ids['imdb']), ['external_source' => 'imdb_id']);
            $id = self::int($found['movie_results'][0] ?? null, 'id');

            if ($id !== null) {
                return $id;
            }
        }

        return $this->search('search/movie', $title, $year === null ? [] : ['year' => $year]);
    }

    /**
     * @return Details|null
     */
    private function episode(WatchedItem|WatchProgress $entry): ?array
    {
        $ids = $entry->provider_ids ?? [];
        $key = 'tmdb:find-episode:'.sha1(json_encode([$ids, $entry->series_name, $entry->year, $entry->season_number, $entry->episode_number]) ?: '');
        $found = Cache::remember($key, now()->addMonth(), fn () => $this->findEpisode($ids, $entry));

        if (! is_array($found) || ! is_int($found['show'] ?? null)) {
            return null;
        }

        $show = $this->cached("tv/{$found['show']}", ['append_to_response' => 'credits']);

        if ($show === null) {
            return null;
        }

        $season = is_int($found['season'] ?? null) ? $found['season'] : null;
        $number = is_int($found['episode'] ?? null) ? $found['episode'] : null;
        $episode = $season !== null && $number !== null
            ? $this->cached("tv/{$found['show']}/season/{$season}/episode/{$number}")
            : null;

        return $this->presentShow($found['show'], $show, $episode, $season, $number);
    }

    /**
     * The series and episode numbers on TMDB: through the episode's imdb or
     * tvdb id, else by searching the series name.
     *
     * @param  array<string, string>  $ids
     * @return array{show?: int, season?: int|null, episode?: int|null}|null empty when not found, null on failure
     */
    private function findEpisode(array $ids, WatchedItem|WatchProgress $entry): ?array
    {
        foreach (['imdb' => 'imdb_id', 'tvdb' => 'tvdb_id'] as $provider => $source) {
            if (! isset($ids[$provider])) {
                continue;
            }

            $found = $this->tmdb->get('find/'.rawurlencode($ids[$provider]), ['external_source' => $source]);
            $result = $found['tv_episode_results'][0] ?? null;
            $show = self::int($result, 'show_id');

            if ($show !== null) {
                return ['show' => $show, 'season' => self::int($result, 'season_number'), 'episode' => self::int($result, 'episode_number')];
            }
        }

        if ($entry->series_name === null) {
            return [];
        }

        $show = $this->search('search/tv', $entry->series_name, $entry->year === null ? [] : ['first_air_date_year' => $entry->year]);

        return match ($show) {
            null => null,
            self::NOT_FOUND => [],
            default => ['show' => $show, 'season' => $entry->season_number, 'episode' => $entry->episode_number],
        };
    }

    /**
     * The first result's id, retrying without the year filter; NOT_FOUND when
     * nothing matches, null when TMDB could not be reached.
     *
     * @param  array<string, int>  $filters
     */
    private function search(string $path, string $query, array $filters): ?int
    {
        foreach ($filters === [] ? [[]] : [$filters, []] as $attempt) {
            $results = $this->tmdb->get($path, ['query' => $query, 'include_adult' => 'false', ...$attempt]);

            if ($results === null) {
                return null;
            }

            $id = self::int($results['results'][0] ?? null, 'id');

            if ($id !== null) {
                return $id;
            }
        }

        return self::NOT_FOUND;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>|null
     */
    private function cached(string $path, array $query = []): ?array
    {
        $key = 'tmdb:'.$path.':'.config('services.tmdb.language').':'.http_build_query($query);

        /** @var array<array-key, mixed>|null */
        return Cache::remember($key, now()->addWeek(), fn () => $this->tmdb->get($path, $query));
    }

    /**
     * @param  array<array-key, mixed>  $movie
     * @return Details
     */
    private function presentMovie(int $id, array $movie): array
    {
        $crew = is_array($movie['credits']['crew'] ?? null) ? $movie['credits']['crew'] : [];

        return [
            'kind' => 'movie',
            'url' => "https://www.themoviedb.org/movie/{$id}",
            'title' => self::str($movie, 'title'),
            'original_title' => self::str($movie, 'original_title'),
            'tagline' => self::str($movie, 'tagline'),
            'overview' => self::str($movie, 'overview'),
            'poster_url' => TmdbClient::image($movie['poster_path'] ?? null, 'w342'),
            'backdrop_url' => TmdbClient::image($movie['backdrop_path'] ?? null, 'w780'),
            'date' => self::str($movie, 'release_date'),
            'runtime_minutes' => self::int($movie, 'runtime') ?: null,
            'genres' => self::names($movie['genres'] ?? null),
            'rating' => self::rating($movie),
            'creators' => self::names(array_filter($crew, fn (mixed $person) => is_array($person) && ($person['job'] ?? null) === 'Director')),
            'seasons' => null,
            'cast' => self::cast($movie),
            'episode' => null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $show
     * @param  array<array-key, mixed>|null  $episode
     * @return Details
     */
    private function presentShow(int $id, array $show, ?array $episode, ?int $season, ?int $number): array
    {
        $runtimes = is_array($show['episode_run_time'] ?? null) ? $show['episode_run_time'] : [];

        return [
            'kind' => 'tv',
            'url' => "https://www.themoviedb.org/tv/{$id}",
            'title' => self::str($show, 'name'),
            'original_title' => self::str($show, 'original_name'),
            'tagline' => self::str($show, 'tagline'),
            'overview' => self::str($show, 'overview'),
            'poster_url' => TmdbClient::image($show['poster_path'] ?? null, 'w342'),
            'backdrop_url' => TmdbClient::image($show['backdrop_path'] ?? null, 'w780'),
            'date' => self::str($show, 'first_air_date'),
            'runtime_minutes' => is_int($runtimes[0] ?? null) ? $runtimes[0] : null,
            'genres' => self::names($show['genres'] ?? null),
            'rating' => self::rating($show),
            'creators' => self::names($show['created_by'] ?? null),
            'seasons' => self::int($show, 'number_of_seasons'),
            'cast' => self::cast($show),
            'episode' => $episode === null ? null : [
                'name' => self::str($episode, 'name'),
                'overview' => self::str($episode, 'overview'),
                'date' => self::str($episode, 'air_date'),
                'runtime_minutes' => self::int($episode, 'runtime') ?: null,
                'still_url' => TmdbClient::image($episode['still_path'] ?? null, 'w300'),
                'season_number' => self::int($episode, 'season_number') ?? $season,
                'episode_number' => self::int($episode, 'episode_number') ?? $number,
                'rating' => self::rating($episode),
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $media
     * @return list<array{name: string, character: string|null, photo_url: string|null}>
     */
    private static function cast(array $media): array
    {
        $cast = is_array($media['credits']['cast'] ?? null) ? $media['credits']['cast'] : [];
        $people = [];

        foreach (array_slice($cast, 0, self::CAST_SIZE) as $person) {
            $name = self::str($person, 'name');

            if ($name !== null) {
                $people[] = [
                    'name' => $name,
                    'character' => self::str($person, 'character'),
                    'photo_url' => TmdbClient::image(is_array($person) ? ($person['profile_path'] ?? null) : null, 'w185'),
                ];
            }
        }

        return $people;
    }

    /**
     * Names of a list of {name: …} objects (genres, directors, creators).
     *
     * @return list<string>
     */
    private static function names(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $names = array_map(fn (mixed $entry) => self::str($entry, 'name'), $list);

        return array_values(array_unique(array_filter($names, fn (?string $name) => $name !== null)));
    }

    /**
     * The average vote out of 10, when anyone voted.
     */
    private static function rating(mixed $media): ?float
    {
        $votes = self::int($media, 'vote_count');
        $average = is_array($media) ? ($media['vote_average'] ?? null) : null;

        return $votes && (is_float($average) || is_int($average)) ? round((float) $average, 1) : null;
    }

    private static function str(mixed $data, string $key): ?string
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function int(mixed $data, string $key): ?int
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_int($value) ? $value : null;
    }
}
