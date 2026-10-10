<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicAssessmentScheme> */
final class AcademicAssessmentSchemeFactory extends Factory
{
    protected $model = AcademicAssessmentScheme::class;

    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'school_id' => 1,
            'academic_session_id' => 1,
            'academic_term_id' => 1,
            'academic_subject_offering_id' => 1,
            'name' => 'Fictional Assessment Scheme',
            'total_marks' => 100,
        ];
    }
}
