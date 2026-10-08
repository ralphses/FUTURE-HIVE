<?php

namespace Tests\Feature;

use App\Models\User;
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
        $user = User::factory()->create([
            'name' => 'Fictional Fixture User',
            'email' => 'fixture@example.test',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Fictional Fixture User',
            'email' => 'fixture@example.test',
        ]);
    }

    public function test_each_test_receives_a_fresh_database(): void
    {
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_seeder_creates_only_the_fictional_demo_user(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
