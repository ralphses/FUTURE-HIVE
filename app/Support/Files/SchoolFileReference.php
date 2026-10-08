<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SchoolFileReference
{
    public function __construct(
        public string $schoolId,
        public string $publicId,
        public string $format,
        public string $resourceType = 'raw',
    ) {
        self::assertSchoolId($schoolId);

        $prefix = sprintf('%s/%s/', trim((string) config('services.cloudinary.upload_prefix', 'schoolos/schools')), $schoolId);

        if (! str_starts_with($publicId, $prefix) || self::containsUnsafePath($publicId)) {
            throw new InvalidArgumentException('The file reference is not scoped to the requested school.');
        }

        if ($resourceType !== 'raw' || preg_match('/^[a-z0-9]+$/i', $format) !== 1) {
            throw new InvalidArgumentException('The file reference contains an unsupported resource type or format.');
        }
    }

    public static function forUpload(string $schoolId): self
    {
        $context = TenantContext::require();
        if ($schoolId !== $context->schoolPublicId) {
            throw new InvalidArgumentException('The file school does not match the trusted tenant context.');
        }

        return self::forTrustedContext($context);
    }

    public static function forTrustedContext(TenantContext $context): self
    {
        self::assertSchoolId($context->schoolPublicId);

        return new self(
            schoolId: $context->schoolPublicId,
            publicId: sprintf(
                '%s/%s/%s',
                trim((string) config('services.cloudinary.upload_prefix', 'schoolos/schools')),
                $context->schoolPublicId,
                (string) Str::uuid7(),
            ),
            format: 'bin',
        );
    }

    private static function assertSchoolId(string $schoolId): void
    {
        if (! Str::isUuid($schoolId) || self::containsUnsafePath($schoolId)) {
            throw new InvalidArgumentException('A valid public school UUID is required.');
        }
    }

    private static function containsUnsafePath(string $value): bool
    {
        return str_contains($value, "\0")
            || str_contains($value, '..')
            || str_starts_with($value, '/')
            || str_contains($value, '\\');
    }
}
