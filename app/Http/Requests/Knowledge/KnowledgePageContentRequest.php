<?php

namespace App\Http\Requests\Knowledge;

use App\Support\KnowledgeContent;
use Illuminate\Validation\Validator;

/**
 * An editor autosave: the title, the document (sanitized by the service) and
 * the revision the editor started from.
 */
class KnowledgePageContentRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'array'],
            'revision' => ['required', 'integer', 'min:0'],
            'force' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título', 'content' => 'conteúdo'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $content = $this->input('content');

            if (! KnowledgeContent::isDocument($content)) {
                $validator->errors()->add('content', 'O conteúdo da página é inválido.');

                return;
            }

            if (strlen((string) json_encode($content)) > (int) config('knowledge.max_content_kb') * 1024) {
                $validator->errors()->add('content', 'A página ficou grande demais. Divida o conteúdo em mais de uma página.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->text('title') ?: 'Sem título',
            'force' => $this->boolean('force'),
        ]);
    }

    /**
     * @return array<mixed>
     */
    public function content(): array
    {
        /** @var array<mixed> */
        return $this->validated('content');
    }
}
