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

final class AcademicSchemeLessonTest extends TestCase
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

    public function test_teacher_can_create_submit_and_resubmit_a_lesson_after_review_return(): void
    {
        [$school, $teacher, $session, $term, $offering] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'lesson-password');
        $base = $this->basePath($school, $session, $term, $offering);
        $this->withToken($token)->postJson($base.'/scheme')->assertCreated();
        $topic = $this->withToken($token)->postJson($base.'/scheme/topics', ['title' => 'Photosynthesis', 'sequence' => 1, 'teaching_week' => 6])->assertCreated()->json('data.id');
        $lesson = $this->withToken($token)->postJson($base.'/lessons', ['topic_id' => $topic, 'lesson_date' => '2025-09-15', 'title' => 'Introduction to photosynthesis', 'objectives' => 'Explain the process'])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->withToken($token)->postJson($base.'/lessons/'.$lesson.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $leader = $this->createLeader($school);
        $leaderToken = $this->loginAndSelect($leader, $school, 'leader-password');
        $this->withToken($leaderToken)->postJson($base.'/lessons/'.$lesson.'/return', ['reason' => 'Add a classroom activity'])->assertOk()->assertJsonPath('data.status', 'changes_requested');
        $this->withToken($token)->patchJson($base.'/lessons/'.$lesson, ['activities' => 'Learners observe fictional leaves'])->assertOk()->assertJsonPath('data.status', 'changes_requested');
        $this->withToken($token)->postJson($base.'/lessons/'.$lesson.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->withToken($leaderToken)->postJson($base.'/lessons/'.$lesson.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_teacher_cannot_write_a_different_teacher_assignment(): void
    {
        [$school, $teacher, $session, $term, $offering] = $this->fixture();
        $other = UserIdentity::factory()->withPassword('other-password')->create();
        $other->contacts()->update(['canonical_value' => 'other-lesson@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $other->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $other->id, 'assigned_at' => now()]);
        $token = $this->loginAndSelect($other, $school, 'other-password');
        $base = $this->basePath($school, $session, $term, $offering);
        $this->withToken($token)->postJson($base.'/lessons', ['title' => 'Unauthorized lesson'])->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string} */
    private function fixture(): array
    {
        $school = School::factory()->create();
        $teacher = UserIdentity::factory()->withPassword('lesson-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'lesson-teacher@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'lesson fixture', function () use ($school, $teacher): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Lesson Session', 'code' => 'LESSON', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Lesson Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Lesson Level', 'code' => 'LL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Lesson Section', 'code' => 'LS', 'sequence' => 1, 'status' => 'active']);
            $classArm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Lesson Arm', 'code' => 'LA', 'capacity' => 30, 'status' => 'active']);
            $subject = AcademicSubject::create(['school_id' => $school->id, 'name' => 'Basic Science', 'code' => 'SCI', 'status' => 'active']);
            $offering = AcademicSubjectOffering::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $classArm->id, 'academic_subject_id' => $subject->id, 'status' => 'active']);
            AcademicTeachingAssignment::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_subject_offering_id' => $offering->id, 'teacher_id' => $teacher->id, 'effective_start' => '2025-09-01', 'status' => 'active', 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

            return [$school, $teacher, (string) $session->public_id, (string) $term->public_id, (string) $offering->public_id];
        });
    }

    private function createLeader(School $school): UserIdentity
    {
        $leader = UserIdentity::factory()->withPassword('leader-password')->create();
        $leader->contacts()->update(['canonical_value' => 'lesson-leader@example.com', 'verified_at' => now()]);
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

    private function basePath(School $school, string $session, string $term, string $offering): string
    {
        return '/api/v1/schools/'.$school->public_id.'/academic-sessions/'.$session.'/terms/'.$term.'/subject-offerings/'.$offering;
    }
}
