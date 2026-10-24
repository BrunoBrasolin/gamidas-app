<?php

namespace Database\Factories;

use App\Enums\StudyColor;
use App\Models\StudySubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySubject>
 */
class StudySubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(2, true),
            'description' => null,
            'accent_color' => StudyColor::Sage,
        ];
    }
}
