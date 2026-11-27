<?php

namespace App\Services;

use App\Enums\TierListKind;
use App\Models\TierListShare;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a user's tier list public (a link with a random token) and private
 * again. Making it public again after that gives a new link, so the old one
 * stays dead.
 */
class TierListShareService
{
    private const TOKEN_LENGTH = 24;

    public function share(User $user, TierListKind $kind): TierListShare
    {
        return TierListShare::query()->firstOrCreate(
            ['user_id' => $user->id, 'kind' => $kind->value],
            // Lowercase, since MySQL compares it ignoring case.
            ['token' => Str::lower(Str::random(self::TOKEN_LENGTH))],
        );
    }

    public function unshare(User $user, TierListKind $kind): void
    {
        TierListShare::query()->where('user_id', $user->id)->where('kind', $kind->value)->delete();
    }

    /** The public address of the list, or null while it is private. */
    public function url(User $user, TierListKind $kind): ?string
    {
        return TierListShare::query()->where('user_id', $user->id)->where('kind', $kind->value)->first()?->url();
    }
}
