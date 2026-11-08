<?php

namespace App\Models;

use App\Enums\RouletteCategory;
use Carbon\CarbonImmutable;
use Database\Factories\RoulettePlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One option of the household's food roulette.
 *
 * @property int $id
 * @property int $household_id
 * @property RouletteCategory $category
 * @property string $name
 * @property int $weight
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['household_id', 'category', 'name', 'weight', 'created_by_user_id'])]
class RoulettePlace extends Model
{
    public const MIN_WEIGHT = 1;

    public const MAX_WEIGHT = 10;

    /** @use HasFactory<RoulettePlaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['category' => RouletteCategory::class, 'weight' => 'integer'];
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
     * @return array{id: int, name: string, weight: int, created_by: string|null}
     */
    public function toPresenter(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'weight' => $this->weight,
            'created_by' => $this->creator?->firstName(),
        ];
    }
}
