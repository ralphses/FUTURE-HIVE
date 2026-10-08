<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchoolMembership> */
final class SchoolMembershipFactory extends Factory
{
    protected $model = SchoolMembership::class;

    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'user_id' => UserIdentity::factory(),
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ];
    }
}
