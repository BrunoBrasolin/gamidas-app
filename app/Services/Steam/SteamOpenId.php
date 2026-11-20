<?php

namespace App\Services\Steam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * "Sign in through Steam" (OpenID 2.0): the user signs in on Steam's own
 * page, which sends them back with their SteamID64, and the server asks
 * Steam to confirm the signed answer. The Steam password never reaches the
 * app.
 */
class SteamOpenId
{
    public const ENDPOINT = 'https://steamcommunity.com/openid/login';

    private const NS = 'http://specs.openid.net/auth/2.0';

    private const IDENTIFIER_SELECT = 'http://specs.openid.net/auth/2.0/identifier_select';

    private const CLAIMED_ID = '#^https://steamcommunity\.com/openid/id/(7656119\d{10})$#';

    /**
     * The Steam page that signs the user in and returns to $returnTo.
     */
    public function loginUrl(string $returnTo, string $realm): string
    {
        return self::ENDPOINT.'?'.http_build_query([
            'openid.ns' => self::NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $returnTo,
            'openid.realm' => $realm,
            'openid.identity' => self::IDENTIFIER_SELECT,
            'openid.claimed_id' => self::IDENTIFIER_SELECT,
        ]);
    }

    /**
     * The SteamID64 Steam vouches for, or null when the answer is not a
     * valid sign-in to $returnTo.
     *
     * @param  array<string, string>  $params  The openid.* parameters, keys with their dots ({@see self::params()}).
     *
     * @throws SteamException when Steam can't be reached to confirm
     */
    public function verify(array $params, string $returnTo): ?string
    {
        if (($params['openid.mode'] ?? null) !== 'id_res'
            || ($params['openid.ns'] ?? null) !== self::NS
            || ($params['openid.op_endpoint'] ?? null) !== self::ENDPOINT
            || ($params['openid.return_to'] ?? null) !== $returnTo
            || ! isset($params['openid.signed'], $params['openid.sig'])
            || preg_match(self::CLAIMED_ID, $params['openid.claimed_id'] ?? '', $match) !== 1
            || ($params['openid.identity'] ?? null) !== $params['openid.claimed_id']) {
            return null;
        }

        // Every field that identifies the user must be covered by the signature.
        $signed = explode(',', $params['openid.signed']);

        foreach (['claimed_id', 'identity', 'return_to', 'op_endpoint', 'response_nonce'] as $field) {
            if (! in_array($field, $signed, true)) {
                return null;
            }
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::ENDPOINT, [
                ...array_filter($params, fn (string $key) => str_starts_with($key, 'openid.'), ARRAY_FILTER_USE_KEY),
                'openid.mode' => 'check_authentication',
            ]);
        } catch (ConnectionException) {
            throw new SteamException('Não foi possível confirmar o login com a Steam agora. Tente de novo.');
        }

        if (! $response->successful() || preg_match('/^is_valid\s*:\s*true\s*$/m', $response->body()) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * The openid.* parameters of a query string. PHP turns dots in parameter
     * names into underscores, so they are read from the raw query.
     *
     * @return array<string, string>
     */
    public static function params(string $queryString): array
    {
        $params = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);

            if (str_starts_with($key, 'openid.')) {
                $params[$key] = urldecode($value);
            }
        }

        return $params;
    }
}
