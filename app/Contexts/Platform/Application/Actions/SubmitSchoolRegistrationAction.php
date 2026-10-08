<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\SubmitSchoolRegistrationData;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Exceptions\IdempotencyKeyReused;
use App\Contexts\Platform\Domain\Models\IdempotencyRecord;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use App\Support\Contacts\CanonicalContact;
use App\Support\Contacts\ContactNormalizer;
use Illuminate\Support\Facades\DB;
use JsonException;

final class SubmitSchoolRegistrationAction
{
    public function __construct(private readonly ContactNormalizer $contactNormalizer) {}

    /**
     * @throws JsonException
     */
    public function execute(SubmitSchoolRegistrationData $data): SchoolRegistration
    {
        $contact = $this->contactNormalizer->normalize($data->contact);
        $fingerprintHash = $this->fingerprintHash($data, $contact);
        $keyHash = $this->keyHash($data->idempotencyKey, $data->ipAddress);

        return DB::transaction(function () use ($data, $contact, $fingerprintHash, $keyHash): SchoolRegistration {
            $existingKey = IdempotencyRecord::query()
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($existingKey !== null) {
                if (! hash_equals($existingKey->fingerprint_hash, $fingerprintHash)) {
                    throw new IdempotencyKeyReused;
                }

                $existingKey->increment('replay_count');
                $existingKey->forceFill(['last_replayed_at' => now()])->save();

                return $existingKey->registration()->firstOrFail();
            }

            $registration = SchoolRegistration::query()
                ->where('contact_type', $contact->type)
                ->where('canonical_contact', $contact->value)
                ->where('status', '!=', SchoolRegistrationStatus::Cancelled->value)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $registration ??= SchoolRegistration::create([
                'school_name' => trim($data->schoolName),
                'school_type' => trim($data->schoolType),
                'state' => trim($data->state),
                'contact_type' => $contact->type,
                'canonical_contact' => $contact->value,
                'consent_version' => trim($data->consentVersion),
                'consented_at' => now(),
                'status' => SchoolRegistrationStatus::PendingVerification,
                'request_id' => $data->requestId,
                'request_ip_hash' => $this->hashMetadata($data->ipAddress),
                'request_user_agent_hash' => $this->hashMetadata($data->userAgent),
            ]);

            IdempotencyRecord::create([
                'key_hash' => $keyHash,
                'fingerprint_hash' => $fingerprintHash,
                'registration_id' => $registration->getKey(),
                'first_request_id' => $data->requestId,
            ]);

            return $registration;
        }, 3);
    }

    /** @throws JsonException */
    private function fingerprintHash(SubmitSchoolRegistrationData $data, CanonicalContact $contact): string
    {
        $payload = [
            'school_name' => trim($data->schoolName),
            'school_type' => trim($data->schoolType),
            'state' => trim($data->state),
            'contact_type' => $contact->type,
            'canonical_contact' => $contact->value,
            'consent_version' => trim($data->consentVersion),
        ];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }

    private function keyHash(string $key, string $ipAddress): string
    {
        return hash_hmac('sha256', 'public-school-registration|'.$ipAddress.'|'.$key, (string) config('app.key'));
    }

    private function hashMetadata(?string $value): ?string
    {
        return $value === null ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
