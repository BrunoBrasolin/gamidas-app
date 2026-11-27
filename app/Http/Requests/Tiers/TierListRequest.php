<?php

namespace App\Http\Requests\Tiers;

use App\Enums\Tier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole tier list: the ids of each tier, in order (Steam app ids for
 * games, show ids for shows).
 */
class TierListRequest extends FormRequest
{
    /**
     * Entries a tier can receive at once.
     */
    private const MAX_PER_TIER = 5_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tiers' => ['present', 'array:'.implode(',', Tier::values())],
            'tiers.*' => ['array', 'max:'.self::MAX_PER_TIER],
            'tiers.*.*' => ['integer', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    public function tiers(): array
    {
        $tiers = [];

        foreach (Tier::values() as $tier) {
            $tiers[$tier] = array_values(array_map('intval', (array) ($this->validated('tiers')[$tier] ?? [])));
        }

        return $tiers;
    }
}
