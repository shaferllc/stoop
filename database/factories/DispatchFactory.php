<?php

namespace Database\Factories;

use App\Models\Dispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispatch>
 */
class DispatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'dispatch',
            'line' => fake()->randomElement(array_keys(config('stoop.lines'))),
            'stop' => fake()->optional()->randomElement(array_keys(config('stoop.stations'))),
            'body' => fake()->sentence(12),
            'status' => 'approved',
        ];
    }
}
