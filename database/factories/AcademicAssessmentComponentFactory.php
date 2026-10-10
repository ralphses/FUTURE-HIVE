<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicAssessmentComponent> */
final class AcademicAssessmentComponentFactory extends Factory
{
    protected $model = AcademicAssessmentComponent::class;

    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'school_id' => 1,
            'academic_assessment_scheme_id' => 1,
            'name' => 'CA1',
            'category' => 'ca',
            'max_marks' => 20,
            'sequence' => 1,
        ];
    }
}
