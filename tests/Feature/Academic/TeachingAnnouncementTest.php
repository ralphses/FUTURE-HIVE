<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubject;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTeachingAssignment;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TeachingAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $this->privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->publicKey = (string) ($details['key'] ?? '');
        config(['auth.jwt.private_key' => $this->privateKey, 'auth.jwt.public_keys' => ['test-key' => $this->publicKey], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
    }

    public function test_teacher_announcement_moves_through_moderated_lifecycle(): void
    {
        [$school, $teacher, $session, $term, $classArm, $offering] = $this->fixture();
        $teacherToken = $this->loginAndSelect($teacher, $school, 'announcement-password');
        $base = '/api/v1/schools/'.$school->public_id.'/announcements';
        $announcement = $this->withToken($teacherToken)->postJson($base, ['session_id' => $session, 'term_id' => $term, 'class_arm_id' => $classArm, 'subject_offering_id' => $offering, 'title' => 'Fictional reminder', 'body' => 'Bring the fictional reading journal.'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withToken($teacherToken)->postJson($base.'/'.$announcement.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $leader = $this->createLeader($school);
        $leaderToken = $this->loginAndSelect($leader, $school, 'announcement-leader-password');
        $this->withToken($leaderToken)->postJson($base.'/'.$announcement.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($leaderToken)->postJson($base.'/'.$announcement.'/publish')->assertOk()->assertJsonPath('data.status', 'published');
        $this->withToken($leaderToken)->postJson($base.'/'.$announcement.'/recall', ['reason' => 'Fictional correction'])->assertOk()->assertJsonPath('data.status', 'recalled');
        $this->assertDatabaseHas('teaching_announcements', ['public_id' => $announcement, 'status' => 'recalled']);
    }

    public function test_teacher_cannot_target_an_unassigned_subject_offering(): void
    {
        [$school, $teacher, $session, $term, $classArm, $offering] = $this->fixture();
        $membership = SchoolMembership::query()->where('school_id', $school->id)->where('user_id', $teacher->id)->firstOrFail();
        $sourceOffering = AcademicSubjectOffering::withoutGlobalScopes()->where('public_id', $offering)->firstOrFail();
        $otherOffering = TenantContext::runInternal(TenantContext::fromMembership($membership), 'announcement other offering', function () use ($school, $sourceOffering): AcademicSubjectOffering {
            $subject = AcademicSubject::create(['school_id' => $school->id, 'name' => 'Fictional History', 'code' => 'HIS', 'status' => 'active']);

            return AcademicSubjectOffering::create(['school_id' => $school->id, 'academic_session_id' => $sourceOffering->academic_session_id, 'academic_term_id' => $sourceOffering->academic_term_id, 'academic_level_id' => $sourceOffering->academic_level_id, 'academic_section_id' => $sourceOffering->academic_section_id, 'academic_class_arm_id' => $sourceOffering->academic_class_arm_id, 'academic_subject_id' => $subject->id, 'status' => 'active']);
        });
        $token = $this->loginAndSelect($teacher, $school, 'announcement-password');
        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/announcements', ['session_id' => $session, 'term_id' => $term, 'subject_offering_id' => $otherOffering->public_id, 'title' => 'Not allowed', 'body' => 'Fictional body'])->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string, 5: string} */
    private function fixture(): array
    {
        $school = School::factory()->create();
        $teacher = UserIdentity::factory()->withPassword('announcement-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'announcement-teacher@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'announcement fixture', function () use ($school, $teacher): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Announcement Session', 'code' => 'ANN', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Announcement Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Announcement Level', 'code' => 'AL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Announcement Section', 'code' => 'AS', 'sequence' => 1, 'status' => 'active']);
            $arm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Announcement Arm', 'code' => 'AA', 'capacity' => 30, 'status' => 'active']);
            $subject = AcademicSubject::create(['school_id' => $school->id, 'name' => 'Fictional Science', 'code' => 'FSC', 'status' => 'active']);
            $offering = AcademicSubjectOffering::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $arm->id, 'academic_subject_id' => $subject->id, 'status' => 'active']);
            AcademicTeachingAssignment::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_subject_offering_id' => $offering->id, 'teacher_id' => $teacher->id, 'effective_start' => '2025-09-01', 'status' => 'active', 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

            return [$school, $teacher, (string) $session->public_id, (string) $term->public_id, (string) $arm->public_id, (string) $offering->public_id];
        });
    }

    private function createLeader(School $school): UserIdentity
    {
        $leader = UserIdentity::factory()->withPassword('announcement-leader-password')->create();
        $leader->contacts()->update(['canonical_value' => 'announcement-leader@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $leader->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'principal')->value('id'), 'assigned_by' => $leader->id, 'assigned_at' => now()]);

        return $leader;
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
