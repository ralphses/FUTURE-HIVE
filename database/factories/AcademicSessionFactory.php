<?php

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicSession> */
final class AcademicSessionFactory extends Factory
{
    protected $model = AcademicSession::class;

    public function definition(): array
    {
        return [
            'name' => 'Fictional Academic Session '.$this->faker->unique()->numberBetween(2025, 2099),
            'code' => 'FY-'.$this->faker->unique()->numberBetween(2025, 2099),
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'status' => 'draft',
        ];
    }
}
