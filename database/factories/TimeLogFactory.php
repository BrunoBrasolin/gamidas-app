<?php

namespace Database\Factories;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeLog>
 */
class TimeLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'time_category_id' => TimeCategory::factory(),
            'user_id' => fn (array $attributes) => TimeCategory::query()->whereKey($attributes['time_category_id'])->value('user_id'),
            'log_date' => '2026-10-21',
            'seconds' => 3600,
        ];
    }
}
