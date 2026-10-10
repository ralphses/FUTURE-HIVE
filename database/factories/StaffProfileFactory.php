<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffProfile> */
final class StaffProfileFactory extends Factory
{
    protected $model = StaffProfile::class;

    public function definition(): array
    {
        $school = School::factory()->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->getKey()]);

        return [
            'school_id' => $school->getKey(),
            'school_membership_id' => $membership->getKey(),
            'staff_number' => 'STAFF-'.$this->faker->unique()->numerify('####'),
            'legal_name' => 'Fictional Staff Member',
            'preferred_name' => null,
            'job_title' => 'Teacher',
            'department' => 'Academic',
            'employment_status' => 'pending',
            'employment_start_date' => null,
            'employment_end_date' => null,
            'status_reason' => null,
            'metadata' => null,
        ];
    }
}
