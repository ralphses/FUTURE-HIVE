<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<School> */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /** @return array<string, string> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' School',
            'status' => 'active',
        ];
    }
}
