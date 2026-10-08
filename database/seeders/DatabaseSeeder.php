<?php

namespace Database\Seeders;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $identity = UserIdentity::create([
            'name' => 'Test User',
            'public_id' => (string) Str::uuid7(),
        ]);

        $identity->contacts()->create([
            'type' => ContactType::Email,
            'canonical_value' => 'test@example.com',
            'is_primary' => true,
        ]);
    }
}
