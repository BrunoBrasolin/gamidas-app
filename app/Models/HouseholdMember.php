<?php

namespace App\Models;

use Database\Factories\HouseholdMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int $user_id
 * @property int $monthly_salary_cents
 * @property string $payment_label_preposition
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Household $household
 * @property-read User $user
 */
#[Fillable(['household_id', 'user_id', 'monthly_salary_cents', 'payment_label_preposition'])]
class HouseholdMember extends Model
{
    /** @use HasFactory<HouseholdMemberFactory> */
    use HasFactory;

    /**
     * Prepositions accepted for the "Pago pelo/pela/por ..." payment label.
     */
    public const PREPOSITIONS = ['pelo', 'pela', 'por'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_salary_cents' => 'integer',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The label of the reciprocal payment action this member performs,
     * e.g. "Pago pelo Bruno".
     */
    public function paymentLabel(): string
    {
        return 'Pago '.$this->payment_label_preposition.' '.$this->user->firstName();
    }
}
