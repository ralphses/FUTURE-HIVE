<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Contexts\Platform\Domain\Models\ImportBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImportAuditReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_leadership_can_read_safe_import_audit_and_download_all_report_formats(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken('school_admin');
        $batch = TenantContext::runInternal(TenantContext::fromMembership($membership), 'audit report setup', function () use ($school, $identity): string {
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'students', 'template_version' => 'v1', 'original_filename' => 'students.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'audit-report'), 'uploaded_by' => $identity->id, 'status' => 'staged', 'row_count' => 1]);
            foreach (['import.uploaded', 'import.staged', 'import.validation.completed', 'import.dry_run.generated'] as $action) {
                AuditEvent::query()->create(['school_id' => $school->id, 'actor_id' => $identity->id, 'action' => $action, 'subject_type' => 'import_batch', 'subject_public_id' => $batch->public_id, 'reason' => null, 'request_id' => (string) Str::uuid(), 'state_transition' => ['to' => 'ready'], 'metadata' => ['safe' => true]]);
            }

            return (string) $batch->public_id;
        });

        $base = '/api/v1/schools/'.$school->public_id.'/imports/'.$batch;
        $auditResponse = $this->withToken($token)->getJson($base.'/audit');
        $auditResponse->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.action', 'import.dry_run.generated');
        $auditResponse->assertJsonMissing(['metadata' => ['safe' => true]])
            ->assertJsonMissing(['canonical_contact' => 'not-returned@example.test'])
            ->assertJsonMissing(['opening_balance' => '35000.00']);
        foreach (['csv' => 'text/csv; charset=UTF-8', 'pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $format => $contentType) {
            $response = $this->withToken($token)->get($base.'/report?format='.$format);
            $response->assertOk()->assertHeader('Content-Type', $contentType);
            self::assertStringContainsString('import-audit-'.$batch, (string) $response->headers->get('Content-Disposition'));
        }
    }

    public function test_import_audit_and_report_are_denied_for_other_schools_and_unprivileged_members(): void
    {
        [$school, $token, $membership, $identity] = $this->schoolToken('school_admin');
        $batch = TenantContext::runInternal(TenantContext::fromMembership($membership), 'audit authorization setup', function () use ($school, $identity): string {
            $batch = ImportBatch::query()->create(['school_id' => $school->id, 'template_key' => 'students', 'template_version' => 'v1', 'original_filename' => 'students.csv', 'format' => 'csv', 'size' => 100, 'checksum' => hash('sha256', 'audit-authorization'), 'uploaded_by' => $identity->id, 'status' => 'staged']);

            return (string) $batch->public_id;
        });
        [$otherSchool, $otherToken] = $this->schoolToken('teacher');
        $this->withToken($otherToken)->getJson('/api/v1/schools/'.$otherSchool->public_id.'/imports/'.$batch.'/audit')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/imports/'.$batch.'/audit')->assertOk();
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
