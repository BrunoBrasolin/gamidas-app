<?php

namespace Database\Factories;

use App\Enums\KnowledgeColor;
use App\Models\KnowledgeSubject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSubject>
 */
class KnowledgeSubjectFactory extends Factory
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
            'accent_color' => KnowledgeColor::Sage,
        ];
    }
}
