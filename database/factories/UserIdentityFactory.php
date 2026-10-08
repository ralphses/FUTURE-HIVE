<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserIdentity> */
class UserIdentityFactory extends Factory
{
    protected $model = UserIdentity::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'password' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (UserIdentity $identity): void {
            $identity->contacts()->create([
                'type' => ContactType::Email,
                'canonical_value' => fake()->unique()->safeEmail(),
                'is_primary' => true,
            ]);
        });
    }

    public function withPassword(string $password = 'password'): static
    {
        return $this->state(['password' => $password]);
    }
}
