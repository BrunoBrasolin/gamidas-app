<?php

namespace App\Services\Steam;

/**
 * Finds the SteamID64 of what a user typed to identify their profile: the
 * SteamID64 itself, a profile URL (".../profiles/<id>" or ".../id/<name>")
 * or the custom URL name alone.
 */
class SteamProfileResolver
{
    private const STEAM_ID = '/^7656119\d{10}$/';

    private const VANITY = '/^[A-Za-z0-9_-]{2,32}$/';

    public function __construct(private readonly SteamClient $steam)
    {
        //
    }

    /**
     * The SteamID64 of an existing account, or null when nothing matches.
     *
     * @throws SteamException
     */
    public function resolve(string $input): ?string
    {
        [$steamId, $vanity] = self::parse($input);

        if ($steamId === null && $vanity !== null) {
            $steamId = $this->steam->resolveVanityUrl($vanity);
        }

        if ($steamId === null || $this->steam->playerSummary($steamId) === null) {
            return null;
        }

        return $steamId;
    }

    /**
     * The SteamID64 or the custom URL name written in the input.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parse(string $input): array
    {
        $input = trim($input);

        if (preg_match('#^(?:https?://)?(?:www\.)?steamcommunity\.com/(profiles|id)/([^/?\#]+)/?(?:[?\#].*)?$#i', $input, $match) === 1) {
            $input = $match[1] === 'profiles' && preg_match(self::STEAM_ID, $match[2]) !== 1 ? '' : $match[2];
        }

        return match (true) {
            preg_match(self::STEAM_ID, $input) === 1 => [$input, null],
            preg_match(self::VANITY, $input) === 1 => [null, $input],
            default => [null, null],
        };
    }
}
