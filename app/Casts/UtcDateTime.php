<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An instant stored as "Y-m-d H:i:s" in UTC on every database driver and
 * exposed in the application timezone, so durations computed from it are
 * immune to daylight-saving shifts and to the database session timezone.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $value, 0, 19), 'UTC');

        return $instant === null ? null : $instant->setTimezone(config('app.timezone'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, config('app.timezone'));

        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
