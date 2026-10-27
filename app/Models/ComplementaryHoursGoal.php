<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many complementary hours the user's course requires.
 *
 * @property int $id
 * @property int $user_id
 * @property int $required_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'required_minutes'])]
class ComplementaryHoursGoal extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required_minutes' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
