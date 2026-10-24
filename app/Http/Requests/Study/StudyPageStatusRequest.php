<?php

namespace App\Http\Requests\Study;

use App\Enums\StudyPageStatus;
use Illuminate\Validation\Rule;

class StudyPageStatusRequest extends StudyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(StudyPageStatus::class)]];
    }

    public function status(): StudyPageStatus
    {
        return StudyPageStatus::from((string) $this->validated('status'));
    }
}
