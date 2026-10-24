<?php

namespace Database\Factories;

use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyPage>
 */
class StudyPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => StudySubject::factory(),
            'title' => fake()->words(3, true),
            'content' => null,
            'content_text' => null,
        ];
    }
}
