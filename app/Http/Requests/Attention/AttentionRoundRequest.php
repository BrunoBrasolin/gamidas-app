<?php

namespace App\Http\Requests\Attention;

use App\Enums\AttentionType;
use App\Services\Attention\AttentionGameService;
use Illuminate\Validation\Rule;

/**
 * A player's result in one challenge, measured on the phone. Only known
 * detail keys are kept (expected and given counts, perseverations).
 *
 * @phpstan-import-type RoundData from AttentionGameService
 */
class AttentionRoundRequest extends AttentionPlayerRequest
{
    public const DETAIL_KEYS = ['expected', 'answer', 'expected_secondary', 'answer_secondary', 'perseverations'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $details = [];

        foreach (self::DETAIL_KEYS as $key) {
            $details['details.'.$key] = ['nullable', 'integer', 'min:0', 'max:999'];
        }

        return [
            ...parent::rules(),
            'type' => ['required', Rule::enum(AttentionType::class)],
            'correct' => ['required', 'boolean'],
            'accuracy' => ['required', 'integer', 'min:0', 'max:100'],
            'hits' => ['required', 'integer', 'min:0', 'max:500'],
            'errors' => ['required', 'integer', 'min:0', 'max:500'],
            'misses' => ['required', 'integer', 'min:0', 'max:500'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'reaction_ms' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'guess' => ['nullable', Rule::enum(AttentionType::class)],
            'details' => ['nullable', 'array'],
            ...$details,
        ];
    }

    /**
     * @return RoundData
     */
    public function round(): array
    {
        $details = array_filter(
            array_intersect_key((array) $this->validated('details', []), array_flip(self::DETAIL_KEYS)),
            fn ($value) => $value !== null,
        );
        $guess = $this->validated('guess');

        return [
            'type' => AttentionType::from((string) $this->validated('type')),
            'correct' => (bool) $this->validated('correct'),
            'accuracy' => (int) $this->validated('accuracy'),
            'hits' => (int) $this->validated('hits'),
            'errors' => (int) $this->validated('errors'),
            'misses' => (int) $this->validated('misses'),
            'duration_ms' => (int) $this->validated('duration_ms'),
            'reaction_ms' => $this->validated('reaction_ms') === null ? null : (int) $this->validated('reaction_ms'),
            'guess' => $guess === null ? null : AttentionType::from((string) $guess),
            'details' => $details === [] ? null : array_map(intval(...), $details),
        ];
    }
}
