<?php

namespace App\Http\Requests\Knowledge;

class KnowledgeSubjectOrderRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids')));
    }
}
