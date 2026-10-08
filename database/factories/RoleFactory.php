<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
final class RoleFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->randomElement(array_keys(AuthorizationCatalogue::roles()));
        $role = AuthorizationCatalogue::roles()[$key];

        return [
            'key' => $key,
            'label' => $role['label'],
            'description' => $role['description'],
            'scope' => 'school',
            'is_active' => true,
        ];
    }
}
