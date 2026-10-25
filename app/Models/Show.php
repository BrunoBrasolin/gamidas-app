<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EventColor;
use App\Enums\EventIcon;
use Carbon\CarbonImmutable;
use Database\Factories\ShowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concert attended by the household, shared by every member: the headliner,
 * its setlist, the opening acts and optional links to the Google Photos album
 * and the Spotify playlist.
 *
 * @property int $id
 * @property int $household_id
 * @property string $band
 * @property string|null $tour
 * @property string $venue
 * @property CarbonImmutable $show_date
 * @property list<string> $setlist
 * @property string|null $photos_url
 * @property string|null $playlist_url
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'band', 'tour', 'venue', 'show_date', 'setlist', 'photos_url', 'playlist_url', 'created_by_user_id'])]
class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_date' => DateOnly::class, 'setlist' => 'array'];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<ShowOpeningAct, $this>
     */
    public function openingActs(): HasMany
    {
        return $this->hasMany(ShowOpeningAct::class)->orderBy('position');
    }

    /**
     * Items of the household's checklist already packed for this show.
     *
     * @return BelongsToMany<ShowChecklistItem, $this>
     */
    public function checkedItems(): BelongsToMany
    {
        return $this->belongsToMany(ShowChecklistItem::class, 'show_checklist_checks')->withTimestamps();
    }

    /**
     * The show as a read-only all-day entry of the agenda, in the shape of
     * {@see CalendarEvent::toPage()}; the agenda links it to the show page.
     *
     * @return array<string, mixed>
     */
    public function toAgendaPage(): array
    {
        return [
            'id' => $this->id,
            'occurrence' => null,
            'title' => $this->band,
            'date' => $this->show_date->toDateString(),
            'time' => null,
            'description' => implode(' · ', array_filter([$this->tour, $this->venue])),
            'color' => EventColor::Violet->value,
            'icon' => EventIcon::Music->value,
            'modified' => false,
            'recurrence' => null,
            'series' => null,
            'show_id' => $this->id,
            'created_by' => $this->creator?->firstName(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
