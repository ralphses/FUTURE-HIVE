<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Domain\Exceptions\IdempotencyKeyReused;
use App\Contexts\Platform\Domain\Models\PublicDemoEnquiry;
use App\Support\Contacts\CanonicalContact;
use App\Support\Contacts\ContactNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class SubmitPublicDemoEnquiryAction
{
    public function __construct(private readonly ContactNormalizer $contactNormalizer) {}

    /** @param array<string, string|null> $input
     * @return array{accepted: bool, request_id: string|null}
     */
    public function execute(array $input, string $idempotencyKey, ?string $requestId, ?string $ip, ?string $userAgent): array
    {
        if (! preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $idempotencyKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['The Idempotency-Key header must be 16 to 128 safe characters.']]);
        }

        try {
            $contact = $this->contactNormalizer->normalize((string) $input['contact']);
            $fingerprint = $this->fingerprint($input, $contact);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contact' => [$exception->getMessage()]]);
        }

        $keyHash = $this->hash('public-demo-enquiry|'.$idempotencyKey);

        $enquiry = DB::transaction(function () use ($input, $contact, $fingerprint, $keyHash, $requestId, $ip, $userAgent): PublicDemoEnquiry {
            $existing = PublicDemoEnquiry::query()->where('idempotency_key_hash', $keyHash)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->fingerprint_hash, $fingerprint)) {
                    throw new IdempotencyKeyReused;
                }

                return $existing;
            }

            $duplicate = PublicDemoEnquiry::query()
                ->where('fingerprint_hash', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($duplicate !== null) {
                return $duplicate;
            }

            return PublicDemoEnquiry::query()->create([
                'name' => trim((string) $input['name']),
                'organization' => isset($input['organization']) ? trim((string) $input['organization']) : null,
                'role' => isset($input['role']) ? trim((string) $input['role']) : null,
                'contact_type' => $contact->type,
                'contact_hash' => $this->hash($contact->type.'|'.$contact->value),
                'category' => (string) $input['category'],
                'message' => trim((string) $input['message']),
                'consent_version' => trim((string) $input['consent_version']),
                'consented_at' => now(),
                'status' => 'received',
                'idempotency_key_hash' => $keyHash,
                'fingerprint_hash' => $fingerprint,
                'request_id' => $requestId,
                'request_ip_hash' => $ip === null ? null : $this->hash($ip),
                'request_user_agent_hash' => $userAgent === null ? null : $this->hash($userAgent),
            ]);
        }, 3);

        return ['accepted' => true, 'request_id' => $enquiry->request_id];
    }

    /** @param array<string, string|null> $input */
    private function fingerprint(array $input, CanonicalContact $contact): string
    {
        try {
            $payload = [
                'name' => trim((string) $input['name']),
                'organization' => isset($input['organization']) ? trim((string) $input['organization']) : null,
                'role' => isset($input['role']) ? trim((string) $input['role']) : null,
                'contact_type' => $contact->type,
                'contact' => $contact->value,
                'category' => $input['category'],
                'message' => trim((string) $input['message']),
                'consent_version' => trim((string) $input['consent_version']),
            ];

            return $this->hash(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw ValidationException::withMessages(['message' => ['The enquiry could not be accepted.']]);
        }
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
