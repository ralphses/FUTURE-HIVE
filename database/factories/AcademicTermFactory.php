<?php

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicTerm> */
final class AcademicTermFactory extends Factory
{
    protected $model = AcademicTerm::class;

    public function definition(): array
    {
        return [
            'academic_session_id' => AcademicSession::factory(),
            'name' => 'Fictional Term '.$this->faker->unique()->numberBetween(1, 99),
            'sequence' => 1,
            'start_date' => '2025-09-01',
            'end_date' => '2025-12-15',
            'status' => 'draft',
        ];
    }
}
