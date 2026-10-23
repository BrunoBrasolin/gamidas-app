<?php

namespace Database\Factories;

use App\Enums\TaskGoalType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(2, true),
            'goal_type' => TaskGoalType::None,
        ];
    }

    /**
     * A task aiming for a number of recorded hours.
     */
    public function timeGoal(int $hours): static
    {
        return $this->state([
            'goal_type' => TaskGoalType::Time,
            'target_minutes' => $hours * 60,
        ]);
    }

    public function completed(): static
    {
        return $this->state(['completed_at' => Date::now()]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => Date::now()]);
    }
}
