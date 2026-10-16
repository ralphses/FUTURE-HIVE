<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Contexts\Identity\Domain\Models\MembershipRole;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_leadership_can_version_preview_and_send_a_safe_notification(): void
    {
        [$school, $token] = $this->schoolToken('school_admin', 'notifications@example.com', 'notifications-password');
        $base = '/api/v1/schools/'.$school->public_id;

        $template = $this->withToken($token)->postJson($base.'/notification-templates', [
            'template_key' => 'announcement.published',
            'channel' => 'in_app',
            'title' => 'Hello {{recipient_name}}',
            'body' => 'Welcome to {{school_name}}.',
        ])->assertCreated()->assertJsonPath('data.template.status', 'draft')->json('data.template.id');

        $this->withToken($token)->postJson($base.'/notification-templates/'.$template.'/activate')->assertOk()->assertJsonPath('data.template.status', 'active');
        $this->withToken($token)->postJson($base.'/notification-templates/'.$template.'/preview')->assertOk()->assertJsonPath('data.preview.title', 'Hello Recipient')->assertJsonMissing(['notifications@example.com']);

        $recipient = UserIdentity::factory()->create();
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $recipient->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', 'teacher')->value('id'), 'assigned_by' => $recipient->id, 'assigned_at' => now()]);

        $created = $this->withToken($token)->postJson($base.'/notifications', [
            'template_id' => $template,
            'channel' => 'in_app',
            'recipient_type' => 'staff',
            'recipient_id' => $recipient->public_id,
            'source_type' => 'manual',
            'idempotency_key' => 'notification-test-1',
        ])->assertCreated()->assertJsonPath('data.notification.status', 'queued');
        $notification = $created->json('data.notification.id');

        $this->withToken($token)->postJson($base.'/notifications', [
            'template_id' => $template,
            'channel' => 'in_app',
            'recipient_type' => 'staff',
            'recipient_id' => $recipient->public_id,
            'source_type' => 'manual',
            'idempotency_key' => 'notification-test-1',
        ])->assertCreated()->assertJsonPath('data.notification.id', $notification);

        $this->withToken($token)->postJson($base.'/notifications/'.$notification.'/send')->assertOk()->assertJsonPath('data.notification.status', 'sent');
    }

    public function test_non_leadership_cannot_manage_notification_templates(): void
    {
        [$school, $token] = $this->schoolToken('teacher', 'notification-teacher@example.com', 'notification-teacher-password');

        $this->withToken($token)->getJson('/api/v1/schools/'.$school->public_id.'/notification-templates')->assertNotFound();
    }

    /** @return array{0: School, 1: string} */
    private function schoolToken(string $role, string $login, string $password): array
    {
        $school = School::factory()->create();
        $identity = UserIdentity::factory()->withPassword($password)->create();
        $identity->contacts()->update(['canonical_value' => $login, 'verified_at' => now()]);
        $membership = SchoolMembership::query()->create(['school_id' => $school->id, 'user_id' => $identity->id, 'status' => 'active', 'joined_at' => now()]);
        MembershipRole::query()->create(['school_membership_id' => $membership->id, 'role_id' => Role::query()->where('key', $role)->value('id'), 'assigned_by' => $identity->id, 'assigned_at' => now()]);
        $token = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password])->assertOk()->json('data.access_token');
        $this->withToken($token)->postJson('/api/v1/auth/context/switch', ['school_id' => $school->public_id])->assertOk();

        return [$school, $token];
    }
}
