<?php

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicSection> */
final class AcademicSectionFactory extends Factory
{
    protected $model = AcademicSection::class;

    public function definition(): array
    {
        $number = $this->faker->unique()->numberBetween(1, 99);

        return ['name' => 'Fictional Section '.$number, 'code' => 'SEC-'.$number, 'sequence' => $number, 'status' => 'active'];
    }
}
