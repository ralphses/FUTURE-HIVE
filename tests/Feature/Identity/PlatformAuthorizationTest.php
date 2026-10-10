<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contexts\Identity\Application\Actions\AssignPlatformRoleAction;
use App\Contexts\Identity\Application\Actions\AuthorizeBreakGlassAccessAction;
use App\Contexts\Identity\Application\Actions\CreateBreakGlassGrantAction;
use App\Contexts\Identity\Application\Actions\ResolvePlatformPermissionsAction;
use App\Contexts\Identity\Application\Actions\RevokeBreakGlassGrantAction;
use App\Contexts\Identity\Application\Actions\RevokePlatformRoleAction;
use App\Contexts\Identity\Application\DTOs\BreakGlassGrantData;
use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\PlatformRoleAssignment;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PlatformAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_catalogue_and_assignments_are_separate_from_school_roles(): void
    {
        $admin = $this->identity('platform-admin@example.com');
        $support = $this->identity('platform-support@example.com');
        $adminAssignment = $this->assign($admin, 'platform_admin');
        $school = School::factory()->create();
        $action = app(AssignPlatformRoleAction::class);

        $assignment = $action->handle($admin, $support, 'platform_support', 'fictional support review');

        self::assertSame('platform', $assignment->role->scope);
        self::assertSame(12, Role::query()->count());
        self::assertSame(69, Permission::query()->count());
        self::assertSame(1, PlatformRoleAssignment::query()->where('user_id', $support->id)->count());
        self::assertSame(0, $support->schoolMemberships()->count());
        self::assertNotSame('', $adminAssignment->public_id);
        self::assertNotSame('', $school->public_id);
    }

    public function test_platform_permissions_union_and_revocation_are_immediate(): void
    {
        $admin = $this->identity('platform-union-admin@example.com');
        $support = $this->identity('platform-union-support@example.com');
        $ops = $this->identity('platform-union-ops@example.com');
        $this->assign($admin, 'platform_admin');
        $supportAssignment = app(AssignPlatformRoleAction::class)->handle($admin, $support, 'platform_support', 'fictional support access');
        app(AssignPlatformRoleAction::class)->handle($admin, $support, 'platform_ops', 'fictional operations access');
        app(AssignPlatformRoleAction::class)->handle($admin, $ops, 'platform_ops', 'fictional operations access');
        $permissions = app(ResolvePlatformPermissionsAction::class);

        self::assertTrue($permissions->allows($support, 'platform.support.access'));
        self::assertTrue($permissions->allows($support, 'platform.operations.read'));
        self::assertTrue($permissions->allows($ops, 'platform.operations.read'));
        self::assertFalse($permissions->allows($ops, 'platform.support.access'));

        app(RevokePlatformRoleAction::class)->handle($admin, $supportAssignment->public_id, 'support access ended');

        self::assertFalse($permissions->allows($support, 'platform.support.access'));
        self::assertTrue($permissions->allows($support, 'platform.operations.read'));
        self::assertSame($admin->id, $supportAssignment->fresh()->revoked_by);
    }

    public function test_platform_roles_do_not_grant_school_permissions_or_school_data_access(): void
    {
        $admin = $this->identity('platform-school-boundary@example.com');
        $this->assign($admin, 'platform_admin');
        $school = School::factory()->create();
        $authorization = app(AuthorizeBreakGlassAccessAction::class);

        self::assertSame('MISSING_PLATFORM_PERMISSION', $authorization->handle($admin, $school->id, 'school.memberships.read')->code);
        self::assertFalse(app(ResolvePlatformPermissionsAction::class)->allows($admin, 'school.memberships.list'));
    }

    public function test_break_glass_access_requires_scope_and_is_sanitized_audited_and_revocable(): void
    {
        $admin = $this->identity('break-glass-admin@example.com');
        $support = $this->identity('break-glass-support@example.com');
        $school = School::factory()->create();
        $this->assign($admin, 'platform_admin');
        app(AssignPlatformRoleAction::class)->handle($admin, $support, 'platform_support', 'fictional support role');
        $grant = app(CreateBreakGlassGrantAction::class)->handle($admin, new BreakGlassGrantData(
            targetUserId: $support->id,
            schoolId: $school->id,
            resourceScope: 'school.metadata.read',
            purpose: 'Fictional approved support investigation',
            approvalContext: ['approver' => 'fictional-approver', 'token' => 'never-persist-this', 'password' => 'never-persist-this'],
            expiresAt: now()->addMinutes(30),
            requestId: '9d8f0c8e-2c5c-4b8e-9f7f-75f0a0cb7e01',
        ));
        $authorization = app(AuthorizeBreakGlassAccessAction::class);

        self::assertTrue($authorization->handle($support, $school->id, 'school.metadata.read')->allowed);
        self::assertSame('GRANT_SCOPE_DENIED', $authorization->handle($support, $school->id, 'school.finance.read')->code);
        self::assertSame('GRANT_SCOPE_DENIED', $authorization->handle($support, $school->id + 1, 'school.metadata.read')->code);
        $approvalContext = json_decode((string) $grant->fresh()->getRawOriginal('approval_context'), true);
        self::assertIsArray($approvalContext);
        self::assertSame('[REDACTED]', $approvalContext['token']);
        self::assertSame('[REDACTED]', $approvalContext['password']);
        self::assertSame(2, AuditEvent::query()->whereIn('action', ['platform.role_assigned', 'platform.break_glass_grant_created'])->count());

        app(RevokeBreakGlassGrantAction::class)->handle($admin, $grant->public_id, 'fictional investigation closed');

        self::assertSame('GRANT_SCOPE_DENIED', $authorization->handle($support, $school->id, 'school.metadata.read')->code);
        self::assertNotNull($grant->fresh()->revoked_at);
        self::assertSame(1, AuditEvent::query()->where('action', 'platform.break_glass_grant_revoked')->count());
    }

    public function test_break_glass_grants_require_future_expiry_and_approval_context(): void
    {
        $admin = $this->identity('break-glass-invalid-admin@example.com');
        $support = $this->identity('break-glass-invalid-support@example.com');
        $school = School::factory()->create();
        $this->assign($admin, 'platform_admin');

        $this->expectException(ValidationException::class);

        app(CreateBreakGlassGrantAction::class)->handle($admin, new BreakGlassGrantData(
            targetUserId: $support->id,
            schoolId: $school->id,
            resourceScope: 'school.metadata.read',
            purpose: '',
            approvalContext: [],
            expiresAt: now()->subMinute(),
        ));
    }

    private function identity(string $email): UserIdentity
    {
        $identity = UserIdentity::factory()->withPassword('fictional-platform-password')->create();
        $identity->contacts()->update(['canonical_value' => $email, 'verified_at' => now()]);

        return $identity;
    }

    private function assign(UserIdentity $identity, string $roleKey): PlatformRoleAssignment
    {
        return PlatformRoleAssignment::query()->create([
            'user_id' => $identity->id,
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
            'assigned_by' => $identity->id,
            'assigned_at' => now(),
        ]);
    }
}
