<?php

namespace App\Http\Requests\Roulette;

use App\Http\Requests\Finance\FinanceRequest;
use App\Models\RoulettePlace;

/**
 * Validates a new weight for a place of the roulette. The place in the route
 * is authorized through its policy before the weight is validated.
 */
class RouletteWeightRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'weight' => ['required', 'integer', 'between:'.RoulettePlace::MIN_WEIGHT.','.RoulettePlace::MAX_WEIGHT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['weight' => 'peso'];
    }

    public function weight(): int
    {
        return (int) $this->validated('weight');
    }
}
