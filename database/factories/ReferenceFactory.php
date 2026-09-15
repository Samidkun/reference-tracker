<?php

namespace Database\Factories;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferenceFactory extends Factory
{
    protected $model = Reference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title'   => fake()->sentence(4),
            'authors' => [fake()->lastName() . ', ' . fake()->firstName()],
            'year'    => fake()->numberBetween(1990, 2025),
            'type'    => fake()->randomElement(['journal', 'book', 'conference', 'thesis', 'web']),
            'doi'     => null,
            'url'     => null,
            'notes'   => null,
        ];
    }
}
