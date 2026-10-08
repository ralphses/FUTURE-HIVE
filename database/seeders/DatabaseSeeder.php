<?php

namespace Database\Seeders;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $contact = UserContact::query()
                ->where('type', ContactType::Email)
                ->where('canonical_value', 'test@example.com')
                ->first();

            $identity = $contact?->identity()->first();

            if (! $identity instanceof UserIdentity) {
                $identity = UserIdentity::create([
                    'name' => 'Test User',
                    'public_id' => (string) Str::uuid7(),
                ]);
            } else {
                $identity->update(['name' => 'Test User']);
            }

            if (! $contact instanceof UserContact) {
                $identity->contacts()->create([
                    'type' => ContactType::Email,
                    'canonical_value' => 'test@example.com',
                    'is_primary' => true,
                ]);
            }
        });
    }
}
