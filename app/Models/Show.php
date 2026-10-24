<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Database\Factories\ShowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concert attended by the household, shared by every member: the headliner,
 * its setlist, the opening acts and an optional link to the Google Photos album.
 *
 * @property int $id
 * @property int $household_id
 * @property string $band
 * @property string|null $tour
 * @property string $venue
 * @property CarbonImmutable $show_date
 * @property list<string> $setlist
 * @property string|null $photos_url
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'band', 'tour', 'venue', 'show_date', 'setlist', 'photos_url', 'created_by_user_id'])]
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
}
