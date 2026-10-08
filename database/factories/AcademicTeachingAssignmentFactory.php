<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicTeachingAssignment> */
final class AcademicTeachingAssignmentFactory extends Factory
{
    protected $model = AcademicTeachingAssignment::class;

    public function definition(): array
    {
        return [
            'effective_start' => now()->toDateString(),
            'status' => 'active',
            'assigned_at' => now(),
        ];
    }
}
