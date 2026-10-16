<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchoolAuditExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_leadership_can_read_filtered_audit_events_and_safe_detail(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken('school_admin');
        $events = $this->createEvents($school, $identity, $membership);

        $base = '/api/v1/schools/'.$school->public_id.'/audit-explorer';
        $response = $this->withToken($token)->getJson($base.'?action=school.lifecycle_changed&transition=active');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $events[0]->public_id)
            ->assertJsonPath('data.0.action', 'school.lifecycle_changed')
            ->assertJsonMissing(['metadata' => ['contact' => 'secret@example.test']])
            ->assertJsonMissing(['financial_amount' => 12500]);

        $this->withToken($token)->getJson($base.'/'.$events[0]->public_id)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $events[0]->public_id)
            ->assertJsonMissing(['metadata' => ['contact' => 'secret@example.test']]);
    }

    public function test_audit_explorer_orders_events_deterministically_and_bounds_date_ranges(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken('principal');
        $this->createEvents($school, $identity, $membership);

        $base = '/api/v1/schools/'.$school->public_id.'/audit-explorer';
        $this->withToken($token)->getJson($base.'?from=2026-10-01&to=2026-10-03')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.action', 'school.lifecycle_changed')
            ->assertJsonPath('data.1.action', 'student.profile_updated');

        $this->withToken($token)->getJson($base.'?from=2026-01-01&to=2026-03-01')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.date_range.0', 'The audit date range must be ordered and bounded to 31 days.');
    }

    public function test_audit_explorer_denies_unprivileged_members_generically(): void
    {
        [$school, $token] = $this->schoolToken('teacher');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/audit-explorer')->assertNotFound();
    }

    public function test_audit_explorer_isolated_from_other_schools(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken('proprietor');
        $event = $this->createEvents($school, $identity, $membership)[0];
        [$otherSchool, $otherToken] = $this->schoolToken('proprietor');

        $this->withToken($otherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/audit-explorer/'.$event->public_id)->assertNotFound();
    }

    /** @return list<AuditEvent> */
    private function createEvents(School $school, UserIdentity $identity, SchoolMembership $membership): array
    {
        $first = AuditEvent::query()->create([
            'school_id' => $school->id,
            'actor_id' => $identity->id,
            'action' => 'school.lifecycle_changed',
            'subject_type' => 'school',
            'subject_public_id' => (string) $school->public_id,
            'request_id' => (string) Str::uuid(),
            'state_transition' => ['to' => 'active', 'internal_id' => 7],
            'metadata' => ['contact' => 'secret@example.test', 'financial_amount' => 12500],
        ]);
        $first->forceFill(['created_at' => '2026-10-02 12:00:00'])->save();

        $second = AuditEvent::query()->create([
            'school_id' => $school->id,
            'actor_id' => $identity->id,
            'action' => 'student.profile_updated',
            'subject_type' => 'student',
            'subject_public_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'state_transition' => ['from' => 'draft', 'to' => 'active'],
            'metadata' => ['safe' => true],
        ]);
        $second->forceFill(['created_at' => '2026-10-01 12:00:00'])->save();

        return [$first, $second];
    }

    /** @return array{0: School, 1: string, 2: SchoolMembership, 3: UserIdentity} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $role.'-'.uniqid().'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token, $membership, $identity];
    }
}
