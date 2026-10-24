<?php

namespace App\Http\Requests\Study;

class StudyReadingRequest extends StudyRequest
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
