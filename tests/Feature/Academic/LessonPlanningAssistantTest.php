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

final class LessonPlanningAssistantTest extends TestCase
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

    public function test_assigned_teacher_receives_unreviewed_ephemeral_suggestion(): void
    {
        [$school, $teacher, $session, $term, $offering] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'lesson-password');

        $response = $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/teaching/lesson-plans/suggestions', [
            'session_id' => $session,
            'term_id' => $term,
            'offering_id' => $offering,
            'objective_focus' => 'Explain the water cycle',
            'lesson_duration_minutes' => 45,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'suggested_unreviewed')->assertJsonPath('data.provider_status', 'deterministic_local_suggestion')->assertJsonPath('data.duration_minutes', 45);
        $response->assertJsonMissing(['school_id', 'teacher_id', 'internal_id', 'student_id']);
        $this->assertDatabaseCount('academic_lessons', 0);
    }

    public function test_unassigned_teacher_cannot_request_a_suggestion(): void
    {
        [$school, , $session, $term, $offering] = $this->fixture();
        $teacher = UserIdentity::factory()->withPassword('other-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'other-suggestion@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);
        $token = $this->loginAndSelect($teacher, $school, 'other-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/teaching/lesson-plans/suggestions', ['session_id' => $session, 'term_id' => $term, 'offering_id' => $offering])->assertNotFound();
    }

    public function test_invalid_input_is_rejected_without_provider_or_record_mutation(): void
    {
        [$school, $teacher] = $this->fixture();
        $token = $this->loginAndSelect($teacher, $school, 'lesson-password');

        $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/teaching/lesson-plans/suggestions', [])->assertUnprocessable();
        $this->assertDatabaseCount('academic_lessons', 0);
    }

    /** @return array{0: School, 1: UserIdentity, 2: string, 3: string, 4: string} */
    private function fixture(): array
    {
        $school = School::factory()->create();
        $teacher = UserIdentity::factory()->withPassword('lesson-password')->create();
        $teacher->contacts()->update(['canonical_value' => 'suggestion-teacher@example.com', 'verified_at' => now()]);
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

        return TenantContext::runInternal(TenantContext::fromMembership($membership), 'lesson planning fixture', function () use ($school, $teacher): array {
            $session = AcademicSession::create(['school_id' => $school->id, 'name' => 'Suggestion Session', 'code' => 'SUGGEST', 'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'status' => 'active']);
            $term = AcademicTerm::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'name' => 'Suggestion Term', 'sequence' => 1, 'start_date' => '2025-09-01', 'end_date' => '2025-12-15', 'status' => 'active']);
            $level = AcademicLevel::create(['school_id' => $school->id, 'name' => 'Suggestion Level', 'code' => 'SL', 'sequence' => 1, 'status' => 'active']);
            $section = AcademicSection::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'name' => 'Suggestion Section', 'code' => 'SS', 'sequence' => 1, 'status' => 'active']);
            $classArm = AcademicClassArm::create(['school_id' => $school->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'name' => 'Suggestion Arm', 'code' => 'SA', 'capacity' => 30, 'status' => 'active']);
            $subject = AcademicSubject::create(['school_id' => $school->id, 'name' => 'Basic Science', 'code' => 'BSC', 'status' => 'active']);
            $offering = AcademicSubjectOffering::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_level_id' => $level->id, 'academic_section_id' => $section->id, 'academic_class_arm_id' => $classArm->id, 'academic_subject_id' => $subject->id, 'status' => 'active']);
            AcademicTeachingAssignment::create(['school_id' => $school->id, 'academic_session_id' => $session->id, 'academic_term_id' => $term->id, 'academic_subject_offering_id' => $offering->id, 'teacher_id' => $teacher->id, 'effective_start' => '2025-09-01', 'status' => 'active', 'assigned_by' => $teacher->id, 'assigned_at' => now()]);

            return [$school, $teacher, (string) $session->public_id, (string) $term->public_id, (string) $offering->public_id];
        });
    }

    private function loginAndSelect(UserIdentity $identity, School $school, string $password): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }
}
