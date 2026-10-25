<?php

namespace App\Http\Requests\Knowledge;

class KnowledgePageTitleRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title')]);
    }
}
