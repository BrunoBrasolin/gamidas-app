<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Typed reads over the JSON the Jellyfin Webhook plugin sends with "Send All
 * Properties". Values may arrive as JSON types or as strings (when a custom
 * template is used), so every getter accepts both and answers null for
 * anything missing or out of range.
 */
final class JellyfinPayload
{
    /** Jellyfin durations are in ticks of 100 ns. */
    private const TICKS_PER_SECOND = 10_000_000;

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private readonly array $data)
    {
        //
    }

    public function string(string $key, int $max = 255): ?string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    public function int(string $key, int $max = 65_535): ?int
    {
        $value = $this->data[$key] ?? null;

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        return is_int($value) && $value >= 0 && $value <= $max ? $value : null;
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        return $value === true || $value === 1 || (is_string($value) && in_array(strtolower(trim($value)), ['true', '1'], true));
    }

    /**
     * The item's length in seconds, from RunTimeTicks or else RunTime
     * ("01:23:45").
     */
    public function runtimeSeconds(): ?int
    {
        $seconds = $this->ticksToSeconds('RunTimeTicks');

        if ($seconds === null && preg_match('/^(\d+):(\d{2}):(\d{2})/', $this->string('RunTime') ?? '', $parts) === 1) {
            $seconds = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) $parts[3];
        }

        return $seconds !== null && $seconds > 0 && $seconds <= 1_000_000 ? $seconds : null;
    }

    /**
     * Where playback is or stopped, in seconds (0 at the start).
     */
    public function positionSeconds(): int
    {
        return min($this->ticksToSeconds('PlaybackPositionTicks') ?? 0, 1_000_000);
    }

    /**
     * The external ids Jellyfin knows the item by ("Provider_tmdb",
     * "Provider_imdb"…), keyed by provider in lowercase.
     *
     * @return array<string, string>
     */
    public function providerIds(): array
    {
        $ids = [];

        foreach ($this->data as $key => $value) {
            if (is_string($key) && preg_match('/^Provider_([A-Za-z0-9]{1,32})$/', $key, $match) === 1 && (is_string($value) || is_int($value)) && trim((string) $value) !== '') {
                $ids[strtolower($match[1])] = mb_substr(trim((string) $value), 0, 64);
            }
        }

        ksort($ids);

        return array_slice($ids, 0, 10, true);
    }

    private function ticksToSeconds(string $key): ?int
    {
        $ticks = $this->data[$key] ?? null;

        if (is_string($ticks) && preg_match('/^\d+$/', $ticks) === 1) {
            $ticks = (int) $ticks;
        }

        return is_int($ticks) && $ticks >= 0 ? intdiv($ticks, self::TICKS_PER_SECOND) : null;
    }

    /**
     * When playback stopped, from UtcTimestamp, in the application timezone.
     * Missing, unreadable or future timestamps fall back to now.
     */
    public function stoppedAt(): CarbonImmutable
    {
        $now = Date::now()->toImmutable();
        $value = $this->string('UtcTimestamp', 64);

        if ($value === null) {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return $now;
        }

        return $at->greaterThan($now) ? $now : $at;
    }
}
