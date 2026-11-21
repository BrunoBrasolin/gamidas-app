<?php

namespace App\Http\Requests\Games;

use App\Enums\GameTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One game's tier, or none to take it off the tier list.
 */
class GameTierRequest extends FormRequest
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
        return ['tier' => ['present', 'nullable', Rule::enum(GameTier::class)]];
    }

    public function tier(): ?GameTier
    {
        return $this->enum('tier', GameTier::class);
    }
}
