<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeAttachment;
use Illuminate\Validation\Validator;

/**
 * The page (or slide) the built-in reader is on, and how many there are.
 */
class KnowledgeAttachmentReadingRequest extends KnowledgeRequest
{
    protected string $routeAbility = 'view';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pages' => ['required', 'integer', 'min:1', 'max:100000'],
            'page' => ['required', 'integer', 'min:1', 'lte:pages'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var KnowledgeAttachment $attachment */
            $attachment = $this->route('attachment');

            if (! $attachment->kind->isPaged()) {
                $validator->errors()->add('page', 'Este arquivo não é lido por páginas.');
            }
        }];
    }
}
