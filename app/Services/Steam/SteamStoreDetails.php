<?php

namespace App\Services\Steam;

use Illuminate\Support\Facades\Cache;

/**
 * A game's store page (description, genres, developers, release date,
 * price, Metacritic score and screenshots), cached for a week. Failures are
 * not cached, so a later visit tries again.
 *
 * @phpstan-type Screenshot array{thumb_url: string, full_url: string}
 * @phpstan-type StoreDetails array{name: string|null, type: string|null, description: string|null, header_url: string|null, background_url: string|null, website: string|null, developers: list<string>, publishers: list<string>, release_date: string|null, coming_soon: bool, genres: list<string>, categories: list<string>, metacritic: array{score: int, url: string|null}|null, price: string|null, discount_percent: int, is_free: bool, platforms: list<string>, controller_support: string|null, recommendations: int|null, screenshots: list<Screenshot>, store_url: string}
 */
class SteamStoreDetails
{
    private const SCREENSHOTS = 8;

    private const TTL = 7 * 24 * 3600;

    public function __construct(private readonly SteamClient $steam)
    {
        //
    }

    /**
     * @return StoreDetails|null
     */
    public function for(int $appId): ?array
    {
        $key = "steam:store:{$appId}:".config('services.steam.language').':'.config('services.steam.country');
        $data = Cache::get($key);

        if (! is_array($data)) {
            $data = $this->steam->appDetails($appId);

            if ($data === null) {
                return null;
            }

            Cache::put($key, $data, self::TTL);
        }

        return $this->present($appId, $data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return StoreDetails
     */
    private function present(int $appId, array $data): array
    {
        $price = is_array($data['price_overview'] ?? null) ? $data['price_overview'] : [];
        $release = is_array($data['release_date'] ?? null) ? $data['release_date'] : [];
        $metacritic = is_array($data['metacritic'] ?? null) ? $data['metacritic'] : [];
        $platforms = is_array($data['platforms'] ?? null) ? $data['platforms'] : [];
        $recommendations = is_array($data['recommendations'] ?? null) ? ($data['recommendations']['total'] ?? null) : null;
        $screenshots = [];

        foreach (array_slice(is_array($data['screenshots'] ?? null) ? $data['screenshots'] : [], 0, self::SCREENSHOTS) as $shot) {
            $thumb = is_array($shot) ? self::url($shot['path_thumbnail'] ?? null) : null;
            $full = is_array($shot) ? self::url($shot['path_full'] ?? null) : null;

            if ($thumb !== null && $full !== null) {
                $screenshots[] = ['thumb_url' => $thumb, 'full_url' => $full];
            }
        }

        return [
            'name' => self::text($data['name'] ?? null),
            'type' => self::text($data['type'] ?? null),
            'description' => self::text($data['short_description'] ?? null),
            'header_url' => self::url($data['header_image'] ?? null),
            'background_url' => self::url($data['background_raw'] ?? $data['background'] ?? null),
            'website' => self::url($data['website'] ?? null),
            'developers' => self::strings($data['developers'] ?? null),
            'publishers' => self::strings($data['publishers'] ?? null),
            'release_date' => self::text($release['date'] ?? null),
            'coming_soon' => ($release['coming_soon'] ?? false) === true,
            'genres' => self::descriptions($data['genres'] ?? null),
            'categories' => self::descriptions($data['categories'] ?? null),
            'metacritic' => is_int($metacritic['score'] ?? null)
                ? ['score' => $metacritic['score'], 'url' => self::url($metacritic['url'] ?? null)]
                : null,
            'price' => self::text($price['final_formatted'] ?? null),
            'discount_percent' => is_int($price['discount_percent'] ?? null) ? $price['discount_percent'] : 0,
            'is_free' => ($data['is_free'] ?? false) === true,
            'platforms' => array_keys(array_filter($platforms, fn (mixed $supported, mixed $name) => $supported === true && in_array($name, ['windows', 'mac', 'linux'], true), ARRAY_FILTER_USE_BOTH)),
            'controller_support' => in_array($data['controller_support'] ?? null, ['full', 'partial'], true) ? $data['controller_support'] : null,
            'recommendations' => is_int($recommendations) ? $recommendations : null,
            'screenshots' => $screenshots,
            'store_url' => SteamClient::storeUrl($appId),
        ];
    }

    /**
     * Plain text: the store sends HTML entities and some markup.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://[^\s"\'<>]+$#', $value) === 1
            ? (preg_replace('#^http://#', 'https://', $value) ?? $value)
            : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn (mixed $value) => self::text($value), $list), fn (?string $value) => $value !== null)));
    }

    /**
     * The descriptions of a list of {id, description} objects (genres, categories).
     *
     * @return list<string>
     */
    private static function descriptions(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return self::strings(array_map(fn (mixed $entry) => is_array($entry) ? ($entry['description'] ?? null) : null, $list));
    }
}
