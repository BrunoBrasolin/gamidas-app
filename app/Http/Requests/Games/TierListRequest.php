<?php

namespace App\Http\Requests\Games;

use App\Enums\GameTier;
use App\Services\Steam\SteamListsService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole tier list: the app ids of each tier, in order.
 */
class TierListRequest extends FormRequest
{
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
            'tiers' => ['present', 'array:'.implode(',', GameTier::values())],
            'tiers.*' => ['array', 'max:'.SteamListsService::MAX_TIERED],
            'tiers.*.*' => ['integer', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    public function tiers(): array
    {
        $tiers = [];

        foreach (GameTier::values() as $tier) {
            $tiers[$tier] = array_values(array_map('intval', (array) ($this->validated('tiers')[$tier] ?? [])));
        }

        return $tiers;
    }
}
