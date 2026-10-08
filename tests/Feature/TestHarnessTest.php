<?php

namespace Tests\Feature;

use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_test_database_starts_empty(): void
    {
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_factory_user_persists_in_the_isolated_test_database(): void
    {
        $user = UserIdentity::factory()->create([
            'name' => 'Fictional Fixture User',
        ]);

        $contact = $user->contacts()->first();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Fictional Fixture User',
        ]);
        $this->assertInstanceOf(UserContact::class, $contact);
        $this->assertDatabaseHas('user_contacts', [
            'id' => $contact->id,
            'canonical_value' => $contact->canonical_value,
        ]);
    }

    public function test_each_test_receives_a_fresh_database(): void
    {
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_seeder_creates_only_the_fictional_demo_user(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_contacts', 1);
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
        ]);
        $this->assertDatabaseHas('user_contacts', [
            'type' => 'email',
            'canonical_value' => 'test@example.com',
        ]);
    }
}
