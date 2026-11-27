<?php

namespace App\Http\Requests\Tiers;

use App\Enums\Tier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One item's tier, or none to take it off the tier list.
 */
class TierRequest extends FormRequest
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
        return ['tier' => ['present', 'nullable', Rule::enum(Tier::class)]];
    }

    public function tier(): ?Tier
    {
        return $this->enum('tier', Tier::class);
    }
}
