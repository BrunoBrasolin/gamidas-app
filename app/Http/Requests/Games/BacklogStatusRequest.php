<?php

namespace App\Http\Requests\Games;

use App\Enums\BacklogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BacklogStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(BacklogStatus::class)]];
    }

    public function status(): BacklogStatus
    {
        return BacklogStatus::from($this->string('status')->toString());
    }
}
