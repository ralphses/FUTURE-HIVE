<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\CreateSchoolRegistrationData;
use App\Contexts\Platform\Domain\Enums\SchoolRegistrationStatus;
use App\Contexts\Platform\Domain\Models\SchoolRegistration;
use App\Support\Contacts\ContactNormalizer;
use Illuminate\Support\Facades\DB;

final class CreateSchoolRegistrationAction
{
    public function __construct(private readonly ContactNormalizer $contactNormalizer) {}

    public function execute(CreateSchoolRegistrationData $data): SchoolRegistration
    {
        $contact = $this->contactNormalizer->normalize($data->contact);

        return DB::transaction(fn (): SchoolRegistration => SchoolRegistration::create([
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
        ]));
    }

    private function hashMetadata(?string $value): ?string
    {
        return $value === null ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
