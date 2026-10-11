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
use Tests\TestCase;

final class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_administrator_can_list_templates(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');

        $response = $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/import-templates');

        $response->assertOk()
            ->assertJsonPath('data.templates.0.key', 'students')
            ->assertJsonPath('data.templates.0.version', 'v1')
            ->assertJsonPath('data.templates.0.fields.0.name', 'student_number')
            ->assertJsonPath('data.templates.1.key', 'guardians')
            ->assertJsonPath('data.templates.2.key', 'staff');
    }

    public function test_templates_download_as_csv_and_xlsx_without_mutating_registry_data(): void
    {
        [$school, $token] = $this->schoolToken('school_admin');

        $csv = $this->withToken($token)->get('/api/v1/schools/'.$school->public_id.'/import-templates/students?format=csv');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertHeader('content-disposition');
        self::assertStringContainsString('student_number,display_name,admission_date,metadata', $csv->streamedContent());
        self::assertStringContainsString('STU-001', $csv->streamedContent());

        $xlsx = $this->withToken($token)->get('/api/v1/schools/'.$school->public_id.'/import-templates/staff?format=xlsx');
        $xlsx->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringStartsWith('PK', $xlsx->streamedContent());
        self::assertSame(2, AuditEvent::query()->where('action', 'import_template.downloaded')->count());
        self::assertSame(1, SchoolMembership::query()->where('school_id', $school->id)->count());
    }

    public function test_context_permission_and_format_are_enforced(): void
    {
        [$school, $token] = $this->schoolToken('teacher');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/import-templates')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/import-templates/students?format=pdf')->assertUnprocessable();
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role): array
    {
        $school = School::factory()->create(['name' => 'Fictional Import School']);
        $identity = UserIdentity::factory()->withPassword('password')->create();
        $identity->contacts()->update(['canonical_value' => $role.'@example.test', 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }
}
