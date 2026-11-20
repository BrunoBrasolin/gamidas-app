<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connecting a Steam account by its profile: the SteamID64, the profile URL
 * or the custom URL name.
 */
class ConnectSteamRequest extends FormRequest
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
        return ['profile' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['profile' => 'perfil da Steam'];
    }

    public function profile(): string
    {
        return trim($this->string('profile')->toString());
    }
}
