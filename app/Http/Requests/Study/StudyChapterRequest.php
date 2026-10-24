<?php

namespace App\Http\Requests\Study;

class StudyChapterRequest extends StudyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:160']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título do capítulo'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title')]);
    }
}
