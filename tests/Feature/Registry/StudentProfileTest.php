<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Registry\Domain\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $privateKey = '';
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        config(['auth.jwt.private_key' => $privateKey, 'auth.jwt.public_keys' => ['test-key' => (string) ($details['key'] ?? '')], 'auth.jwt.current_kid' => 'test-key', 'auth.jwt.issuer' => 'https://schoolos.test', 'auth.jwt.audience' => 'schoolos-api']);
    }

    public function test_profile_can_be_created_and_updated_without_changing_admission_state(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $token = $this->loginAndSelect($identity, $school);
        $student = $this->admit($token, $school);

        $this->withToken($token)->putJson($this->profilePath($school, $student), [
            'legal_name' => 'Fictional Learner',
            'preferred_name' => 'Fictional',
            'date_of_birth' => '2012-04-05',
            'gender' => 'undisclosed',
            'notes' => 'Fictional profile note',
            'school_id' => 999,
            'student_id' => 'forged',
        ])->assertOk()->assertJsonPath('data.legal_name', 'Fictional Learner')->assertJsonPath('data.date_of_birth', '2012-04-05');

        $this->withToken($token)->getJson($this->profilePath($school, $student))->assertOk()->assertJsonPath('data.preferred_name', 'Fictional');
        self::assertSame('pending', Student::query()->where('public_id', $student)->value('status'));
    }

    public function test_profile_validation_and_cross_school_access_are_denied(): void
    {
        [$school, $identity] = $this->schoolWithAdmin();
        $otherSchool = School::factory()->create(['name' => 'Fictional Other Academy']);
        $token = $this->loginAndSelect($identity, $school);
        $student = $this->admit($token, $school);

        $this->withToken($token)->putJson($this->profilePath($school, $student), ['legal_name' => '', 'gender' => 'unknown'])->assertUnprocessable();
        $this->withToken($token)->getJson($this->profilePath($otherSchool, $student))->assertNotFound();
    }

    /** @return array{0: School, 1: UserIdentity} */
    private function schoolWithAdmin(): array
    {
        $school = School::factory()->create(['name' => 'Fictional Student Profile Academy']);
        $identity = UserIdentity::factory()->withPassword('profile-password')->create();
        $membership = SchoolMembership::factory()->create(['school_id' => $school->id, 'user_id' => $identity->id]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'school_admin')->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return [$school, $identity];
    }

    private function loginAndSelect(UserIdentity $identity, School $school): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => $identity->contacts()->firstOrFail()->canonical_value, 'password' => 'profile-password'])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return $token;
    }

    private function admit(string $token, School $school): string
    {
        return $this->withToken($token)->postJson('/api/v1/schools/'.$school->public_id.'/students', ['student_number' => 'PROFILE-001', 'display_name' => 'Fictional Learner'])->assertCreated()->json('data.id');
    }

    private function profilePath(School $school, string $student): string
    {
        return '/api/v1/schools/'.$school->public_id.'/students/'.$student.'/profile';
    }
}
