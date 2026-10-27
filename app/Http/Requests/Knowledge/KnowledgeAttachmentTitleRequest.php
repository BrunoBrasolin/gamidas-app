<?php

namespace App\Http\Requests\Knowledge;

/**
 * A new name for a file; its extension is kept whatever is typed.
 */
class KnowledgeAttachmentTitleRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:190']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'nome'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title')]);
    }
}
