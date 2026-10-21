<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EventColor;
use App\Enums\EventIcon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One occurrence of a recurring event that was changed or deleted on its own.
 * A changed occurrence keeps a full copy of its values, so later edits to the
 * series don't override it; a cancelled one has no values.
 *
 * @property int $id
 * @property int $calendar_event_id
 * @property CarbonImmutable $original_date
 * @property bool $cancelled
 * @property string|null $title
 * @property CarbonImmutable|null $event_date
 * @property string|null $event_time
 * @property string|null $description
 * @property EventColor|null $color
 * @property EventIcon|null $icon
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['calendar_event_id', 'original_date', 'cancelled', 'title', 'event_date', 'event_time', 'description', 'color', 'icon', 'updated_by_user_id'])]
class CalendarEventException extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_date' => DateOnly::class,
            'cancelled' => 'boolean',
            'event_date' => DateOnly::class,
            'color' => EventColor::class,
            'icon' => EventIcon::class,
        ];
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
