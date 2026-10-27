<?php

namespace App\Services\Tmdb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin client for the TMDB API (v3), authenticated with the account's read
 * access token. Every failure answers null, so pages fall back to what
 * Jellyfin sent instead of breaking.
 */
class TmdbClient
{
    private const BASE_URL = 'https://api.themoviedb.org/3/';

    private const IMAGE_URL = 'https://image.tmdb.org/t/p/';

    public function configured(): bool
    {
        return filled(config('services.tmdb.token'));
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('services.tmdb.token'))
                ->acceptJson()
                ->timeout(5)
                ->get(self::BASE_URL.ltrim($path, '/'), ['language' => (string) config('services.tmdb.language'), ...$query]);
        } catch (ConnectionException $exception) {
            Log::warning('TMDB unreachable', ['path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('TMDB request failed', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * The public URL of an image path ("/abc.jpg") at a TMDB size ("w342").
     */
    public static function image(mixed $path, string $size): ?string
    {
        return is_string($path) && $path !== '' ? self::IMAGE_URL.$size.$path : null;
    }
}
