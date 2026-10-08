<?php

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicLevel> */
final class AcademicLevelFactory extends Factory
{
    protected $model = AcademicLevel::class;

    public function definition(): array
    {
        $number = $this->faker->unique()->numberBetween(1, 99);

        return ['name' => 'Fictional Level '.$number, 'code' => 'LV-'.$number, 'sequence' => $number, 'stage' => 'primary', 'status' => 'active'];
    }
}
