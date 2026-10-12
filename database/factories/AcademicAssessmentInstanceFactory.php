<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicAssessmentInstance> */
final class AcademicAssessmentInstanceFactory extends Factory
{
    protected $model = AcademicAssessmentInstance::class;

    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'school_id' => 1,
            'academic_session_id' => 1,
            'academic_term_id' => 1,
            'academic_subject_offering_id' => 1,
            'academic_assessment_policy_version_id' => 1,
            'academic_grading_scale_version_id' => 1,
            'title' => 'Fictional Assessment Instance',
            'assessment_date' => now()->toDateString(),
            'instructions' => 'Fictional instructions for a test record.',
            'status' => 'draft',
        ];
    }
}
