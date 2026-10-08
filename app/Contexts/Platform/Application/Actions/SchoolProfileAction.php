<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\SchoolProfile;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Contacts\ContactNormalizer;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Files\SchoolFileReference;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SchoolProfileAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ContactNormalizer $contacts,
        private readonly CloudinaryAssetStore $files,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(Authenticatable $actor, string $schoolPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.read');

        return $this->serialize($this->profile());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function update(Authenticatable $actor, string $schoolPublicId, array $attributes): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.manage');

        return DB::transaction(function () use ($actor, $schoolPublicId, $attributes): array {
            $profile = $this->profileForUpdate();
            $before = $this->auditableAttributes($profile);
            $updates = [];

            foreach (['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'timezone'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $updates[$field] = $attributes[$field];
                }
            }

            if (array_key_exists('contact', $attributes)) {
                if ($attributes['contact'] === null) {
                    $updates['contact_type'] = null;
                    $updates['canonical_contact'] = null;
                } else {
                    $contact = $this->contacts->normalize((string) $attributes['contact']);
                    $updates['contact_type'] = $contact->type;
                    $updates['canonical_contact'] = $contact->value;
                }
            }

            $profile->fill($updates);
            $profile->save();

            $this->audit->execute(new AuditEventData(
                action: 'school.profile_updated',
                subjectType: SchoolProfile::class,
                subjectPublicId: $schoolPublicId,
                schoolId: TenantContext::require()->schoolId,
                actorId: (int) $actor->getAuthIdentifier(),
                requestId: Context::get('request_id'),
                authorizationContext: ['permission' => 'school.settings.manage'],
                stateTransition: ['from' => $before, 'to' => $this->auditableAttributes($profile)],
                requiresSchoolContext: true,
            ));

            return $this->serialize($profile);
        });
    }

    /** @return array<string, mixed> */
    public function uploadLogo(Authenticatable $actor, string $schoolPublicId, UploadedFile $file): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.manage');
        $profile = $this->profileForUpdate();
        $oldPublicId = (string) ($profile->getAttribute('logo_public_id') ?? '');
        $reference = $this->files->store($file, $schoolPublicId);

        $profile->fill([
            'logo_public_id' => $reference->publicId,
            'logo_format' => $reference->format,
            'logo_resource_type' => $reference->resourceType,
        ]);
        $profile->save();

        $this->audit->execute(new AuditEventData(
            action: 'school.profile_logo_replaced',
            subjectType: SchoolProfile::class,
            subjectPublicId: $schoolPublicId,
            schoolId: TenantContext::require()->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'school.settings.manage'],
            stateTransition: ['logo_present' => false, 'replaced' => $oldPublicId !== ''],
            requiresSchoolContext: true,
        ));

        return $this->serialize($profile);
    }

    /** @return array<string, mixed> */
    public function removeLogo(Authenticatable $actor, string $schoolPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.manage');
        $profile = $this->profileForUpdate();
        $hadLogo = $profile->getAttribute('logo_public_id') !== null;
        $profile->fill(['logo_public_id' => null, 'logo_format' => null, 'logo_resource_type' => null]);
        $profile->save();

        $this->audit->execute(new AuditEventData(
            action: 'school.profile_logo_removed',
            subjectType: SchoolProfile::class,
            subjectPublicId: $schoolPublicId,
            schoolId: TenantContext::require()->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'school.settings.manage'],
            stateTransition: ['logo_present' => $hadLogo, 'to' => false],
            requiresSchoolContext: true,
        ));

        return $this->serialize($profile);
    }

    private function authorize(Authenticatable $actor, string $schoolPublicId, string $permission): void
    {
        $context = TenantContext::require();

        if ($context->schoolPublicId !== $schoolPublicId || ! $this->permissions->allows($actor, $schoolPublicId, $permission)) {
            throw (new ModelNotFoundException)->setModel(SchoolProfile::class);
        }
    }

    private function profile(): SchoolProfile
    {
        return SchoolProfile::query()->first() ?? throw new InvalidArgumentException('The school profile is unavailable.');
    }

    private function profileForUpdate(): SchoolProfile
    {
        return SchoolProfile::query()->lockForUpdate()->first() ?? throw new InvalidArgumentException('The school profile is unavailable.');
    }

    /** @return array<string, mixed> */
    private function serialize(SchoolProfile $profile): array
    {
        $logoReference = $profile->getAttribute('logo_public_id');
        $logo = ['available' => $logoReference !== null, 'download_url' => null];

        if (is_string($logoReference) && $logoReference !== '') {
            $logo['download_url'] = $this->files->temporaryDownloadUrl(new SchoolFileReference(
                schoolId: TenantContext::require()->schoolPublicId,
                publicId: $logoReference,
                format: (string) $profile->getAttribute('logo_format'),
                resourceType: (string) $profile->getAttribute('logo_resource_type'),
            ));
        }

        return [
            'school_id' => TenantContext::require()->schoolPublicId,
            'contact' => $profile->getAttribute('canonical_contact'),
            'contact_type' => $profile->getAttribute('contact_type'),
            'address_line1' => $profile->getAttribute('address_line1'),
            'address_line2' => $profile->getAttribute('address_line2'),
            'city' => $profile->getAttribute('city'),
            'state' => $profile->getAttribute('state'),
            'postal_code' => $profile->getAttribute('postal_code'),
            'country' => $profile->getAttribute('country'),
            'timezone' => $profile->getAttribute('timezone'),
            'logo' => $logo,
        ];
    }

    /** @return array{contact_type: mixed, address_line1: mixed, address_line2: mixed, city: mixed, state: mixed, postal_code: mixed, timezone: mixed, logo_present: bool} */
    private function auditableAttributes(SchoolProfile $profile): array
    {
        return [
            'contact_type' => $profile->getAttribute('contact_type'),
            'address_line1' => $profile->getAttribute('address_line1'),
            'address_line2' => $profile->getAttribute('address_line2'),
            'city' => $profile->getAttribute('city'),
            'state' => $profile->getAttribute('state'),
            'postal_code' => $profile->getAttribute('postal_code'),
            'timezone' => $profile->getAttribute('timezone'),
            'logo_present' => $profile->getAttribute('logo_public_id') !== null,
        ];
    }
}
