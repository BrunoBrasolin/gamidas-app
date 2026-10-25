<?php

namespace App\Http\Requests\Knowledge;

class KnowledgeReadingRequest extends KnowledgeRequest
{
    protected string $routeAbility = 'view';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['position' => ['required', 'integer', 'min:0', 'max:1000']];
    }
}
