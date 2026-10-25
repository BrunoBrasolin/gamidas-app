<?php

namespace Database\Factories;

use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgePage>
 */
class KnowledgePageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => KnowledgeSubject::factory(),
            'title' => fake()->words(3, true),
            'content' => null,
            'content_text' => null,
        ];
    }
}
