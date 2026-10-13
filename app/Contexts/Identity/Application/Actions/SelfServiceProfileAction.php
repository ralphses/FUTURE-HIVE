<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Application\Actions;

use App\Contexts\Identity\Domain\Enums\ContactType;
use App\Contexts\Identity\Domain\Models\UserContact;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Identity\Domain\Models\UserNotificationPreference;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class SelfServiceProfileAction
{
    /** @var list<string> */
    private const PREFERENCE_KEYS = ['finance_reminders', 'academic_updates', 'attendance_updates', 'announcements'];

    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return array<string, mixed> */
    public function profile(UserIdentity $identity): array
    {
        $identity->loadMissing('activeContacts');

        return [
            'id' => (string) $identity->public_id,
            'name' => (string) $identity->name,
            'contacts' => $identity->activeContacts->map(static function (UserContact $contact): array {
                $type = $contact->getAttribute('type');

                return [
                    'type' => $type instanceof ContactType ? $type->value : (string) $type,
                    'verified' => $contact->verified_at !== null,
                    'is_primary' => (bool) $contact->is_primary,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array{name: string}  $input
     * @return array<string, mixed>
     */
    public function updateProfile(UserIdentity $identity, array $input): array
    {
        $name = trim($input['name']);
        $identity->forceFill(['name' => $name])->save();

        $this->audit->execute(new AuditEventData(
            action: 'identity.self_profile_updated',
            subjectType: 'user_identity',
            actorId: (int) $identity->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['fields' => ['name']],
        ));

        return $this->profile($identity->refresh());
    }

    /** @return array<string, mixed> */
    public function preferences(UserIdentity $identity): array
    {
        $stored = UserNotificationPreference::query()->where('user_id', $identity->getKey())->value('preferences') ?? [];

        return ['preferences' => $this->normalizedPreferences(is_array($stored) ? $stored : [])];
    }

    /**
     * @param  array{preferences: array<string, mixed>}  $input
     * @return array<string, mixed>
     */
    public function updatePreferences(UserIdentity $identity, array $input): array
    {
        $preferences = $this->normalizedPreferences($input['preferences']);

        DB::transaction(function () use ($identity, $preferences): void {
            UserNotificationPreference::query()->updateOrCreate(
                ['user_id' => $identity->getKey()],
                ['preferences' => $preferences],
            );
        });

        $this->audit->execute(new AuditEventData(
            action: 'identity.notification_preferences_updated',
            subjectType: 'user_notification_preferences',
            actorId: (int) $identity->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['preference_keys' => self::PREFERENCE_KEYS],
        ));

        return ['preferences' => $preferences];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool>
     */
    private function normalizedPreferences(array $values): array
    {
        $normalized = [];
        foreach (self::PREFERENCE_KEYS as $key) {
            $normalized[$key] = (bool) ($values[$key] ?? false);
        }

        return $normalized;
    }
}
