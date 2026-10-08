<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Application\Actions\AuthorizeSchoolObjectAction;
use App\Contexts\Identity\Domain\Authorization\ClassAssignmentSubject;
use App\Contexts\Identity\Domain\Authorization\FinancialScopeSubject;
use App\Contexts\Identity\Domain\Authorization\GuardianLinkSubject;
use App\Contexts\Identity\Domain\Authorization\StudentSelfSubject;
use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ObjectAuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_teacher_can_manage_visible_editable_assignment_but_unassigned_and_school_admin_cannot_bypass_scope(): void
    {
        $school = School::factory()->create();
        $teacher = $this->identity('teacher-policy@example.com');
        $admin = $this->identity('admin-policy@example.com');
        $this->membership($school, $teacher, 'teacher');
        $this->membership($school, $admin, 'school_admin');
        $subject = new ClassAssignmentSubject($school->public_id, 'assignment-fictional-001', $teacher->id, 'active', true, true);
        $unassigned = new ClassAssignmentSubject($school->public_id, 'assignment-fictional-002', $this->identity('other-policy@example.com')->id, 'active', true, true);
        $authorize = app(AuthorizeSchoolObjectAction::class);

        self::assertTrue($authorize->handle($teacher, 'academic.assignments.manage', $subject)->allowed);
        self::assertSame('OBJECT_SCOPE_DENIED', $authorize->handle($teacher, 'academic.assignments.read', $unassigned)->code);
        self::assertSame('OBJECT_SCOPE_DENIED', $authorize->handle($admin, 'academic.assignments.read', $subject)->code);
        self::assertSame('LIFECYCLE_DENIED', $authorize->handle($teacher, 'academic.assignments.read', new ClassAssignmentSubject($school->public_id, 'assignment-fictional-001', $teacher->id, 'active', false, true))->code);
    }

    public function test_guardian_link_requires_verified_effective_relationship_and_matching_identity(): void
    {
        $school = School::factory()->create();
        $guardian = $this->identity('guardian-policy@example.com');
        $other = $this->identity('other-guardian-policy@example.com');
        $this->membership($school, $guardian, 'parent_guardian');
        $this->membership($school, $other, 'parent_guardian');
        $authorize = app(AuthorizeSchoolObjectAction::class);
        $verified = new GuardianLinkSubject($school->public_id, 'link-fictional-001', $guardian->id, 9001, true, true, 'active');

        self::assertTrue($authorize->handle($guardian, 'guardian.links.read', $verified)->allowed);
        self::assertSame('OBJECT_SCOPE_DENIED', $authorize->handle($other, 'guardian.links.read', $verified)->code);
        self::assertSame('RELATIONSHIP_UNVERIFIED', $authorize->handle($guardian, 'guardian.links.read', new GuardianLinkSubject($school->public_id, 'link-fictional-002', $guardian->id, 9002, false, true, 'active'))->code);
        self::assertSame('RELATIONSHIP_UNVERIFIED', $authorize->handle($guardian, 'guardian.links.read', new GuardianLinkSubject($school->public_id, 'link-fictional-003', $guardian->id, 9003, true, false, 'active'))->code);
    }

    public function test_student_self_and_verified_guardian_access_are_narrowly_scoped(): void
    {
        $school = School::factory()->create();
        $student = $this->identity('student-policy@example.com');
        $guardian = $this->identity('guardian-student-policy@example.com');
        $other = $this->identity('other-student-policy@example.com');
        $this->membership($school, $student, 'student');
        $this->membership($school, $guardian, 'parent_guardian');
        $this->membership($school, $other, 'student');
        $subject = new StudentSelfSubject($school->public_id, 'student-fictional-001', $student->id, true, true, [$guardian->id]);
        $authorize = app(AuthorizeSchoolObjectAction::class);

        self::assertTrue($authorize->handle($student, 'student.self.read', $subject)->allowed);
        self::assertTrue($authorize->handle($guardian, 'guardian.links.read', $subject)->allowed);
        self::assertSame('OBJECT_SCOPE_DENIED', $authorize->handle($other, 'student.self.read', $subject)->code);
        self::assertSame('LIFECYCLE_DENIED', $authorize->handle($student, 'student.self.read', new StudentSelfSubject($school->public_id, 'student-fictional-002', $student->id, false, true, []))->code);
    }

    public function test_financial_access_requires_permission_and_matching_scope(): void
    {
        $school = School::factory()->create();
        $bursar = $this->identity('bursar-policy@example.com');
        $admin = $this->identity('admin-finance-policy@example.com');
        $this->membership($school, $bursar, 'bursar');
        $this->membership($school, $admin, 'school_admin');
        $subject = new FinancialScopeSubject($school->public_id, 'account-fictional-001', true, [$bursar->id], [$bursar->id]);
        $authorize = app(AuthorizeSchoolObjectAction::class);

        self::assertTrue($authorize->handle($bursar, 'finance.records.read', $subject)->allowed);
        self::assertTrue($authorize->handle($bursar, 'finance.records.manage', $subject)->allowed);
        self::assertSame('MISSING_PERMISSION', $authorize->handle($admin, 'finance.records.read', $subject)->code);
        self::assertSame('LIFECYCLE_DENIED', $authorize->handle($bursar, 'finance.records.read', new FinancialScopeSubject($school->public_id, 'account-fictional-002', false, [$bursar->id], [$bursar->id]))->code);
    }

    public function test_revoked_role_or_membership_loses_object_access_immediately(): void
    {
        $school = School::factory()->create();
        $teacher = $this->identity('revoked-policy@example.com');
        $membership = $this->membership($school, $teacher, 'teacher');
        $subject = new ClassAssignmentSubject($school->public_id, 'assignment-fictional-003', $teacher->id, 'active', true, true);
        $authorize = app(AuthorizeSchoolObjectAction::class);

        self::assertTrue($authorize->handle($teacher, 'academic.assignments.read', $subject)->allowed);
        MembershipRole::query()->where('school_membership_id', $membership->id)->update(['revoked_at' => now(), 'revoked_reason' => 'test']);
        self::assertSame('MISSING_PERMISSION', $authorize->handle($teacher, 'academic.assignments.read', $subject)->code);
        $membership->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'test']);
        self::assertSame('INACTIVE_MEMBERSHIP', $authorize->handle($teacher, 'academic.assignments.read', $subject)->code);
    }

    private function identity(string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('fictional-policy-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);

        return $identity;
    }

    private function membership(School $school, UserIdentity $identity, string $roleKey): SchoolMembership
    {
        $membership = SchoolMembership::create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $roleKey)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);

        return $membership;
    }
}
