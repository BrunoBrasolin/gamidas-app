<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgePageStatus;
use Illuminate\Validation\Rule;

class KnowledgePageStatusRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(KnowledgePageStatus::class)]];
    }

    public function status(): KnowledgePageStatus
    {
        return KnowledgePageStatus::from((string) $this->validated('status'));
    }
}
