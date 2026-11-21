<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The balance of an investment position on a day, recorded by each sync (the
 * last sync of the day wins). Kept after the position is withdrawn, so the
 * history of the total stays right; deleted with the connection.
 *
 * @property int $id
 * @property int $bank_connection_id
 * @property int $user_id
 * @property string $pluggy_id
 * @property CarbonImmutable $date
 * @property int $balance_cents
 * @property int|null $invested_cents
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['bank_connection_id', 'user_id', 'pluggy_id', 'date', 'balance_cents', 'invested_cents'])]
class BankInvestmentSnapshot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'balance_cents' => 'integer',
            'invested_cents' => 'integer',
        ];
    }
}
