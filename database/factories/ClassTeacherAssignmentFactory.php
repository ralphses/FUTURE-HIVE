<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Registry\Domain\Models\ClassTeacherAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassTeacherAssignment> */
final class ClassTeacherAssignmentFactory extends Factory
{
    protected $model = ClassTeacherAssignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => fake()->uuid(),
            'school_id' => 1,
            'academic_session_id' => 1,
            'academic_term_id' => 1,
            'academic_class_arm_id' => 1,
            'school_membership_id' => 1,
            'staff_profile_id' => 1,
            'effective_start' => '2025-09-01',
            'effective_end' => null,
            'status' => 'active',
            'assigned_by' => 1,
            'assigned_at' => now(),
        ];
    }
}
