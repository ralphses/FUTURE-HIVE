<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipRole> */
final class MembershipRoleFactory extends Factory
{
    protected $model = MembershipRole::class;

    public function definition(): array
    {
        return [
            'school_membership_id' => SchoolMembership::factory(),
            'role_id' => Role::factory(),
            'assigned_at' => now(),
        ];
    }
}
