<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;

final class CloudinaryAssetStore
{
    public function __construct(
        private readonly CloudinaryAssetClient $client,
        private readonly MalwareScanner $scanner,
    ) {}

    public function store(UploadedFile $file, string $schoolId): SchoolFileReference
    {
        $scanResult = $this->scanner->scan($file);

        if ($scanResult !== FileScanResult::Clean) {
            throw new UnsafeFileUpload($scanResult);
        }

        $context = TenantContext::require();
        if ($schoolId !== $context->schoolPublicId) {
            throw new UnsafeFileUpload(FileScanResult::Unavailable);
        }

        $reference = SchoolFileReference::forUpload($schoolId);
        $response = $this->client->upload($file, [
            'public_id' => $reference->publicId,
            'resource_type' => 'raw',
            'type' => 'authenticated',
            'use_filename' => false,
            'unique_filename' => false,
            'overwrite' => false,
        ]);

        $format = (string) ($response['format'] ?? 'bin');

        return new SchoolFileReference(
            schoolId: $reference->schoolId,
            publicId: (string) ($response['public_id'] ?? $reference->publicId),
            format: $format,
            resourceType: (string) ($response['resource_type'] ?? 'raw'),
        );
    }

    public function temporaryDownloadUrl(SchoolFileReference $reference): string
    {
        $this->assertTrustedReference($reference);

        return URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addSeconds((int) config('services.cloudinary.download_ttl', 300)),
            [
                'school_id' => $reference->schoolId,
                'public_id' => $reference->publicId,
                'format' => $reference->format,
            ],
        );
    }

    public function cloudinaryDownloadUrl(SchoolFileReference $reference): string
    {
        $this->assertTrustedReference($reference);

        return $this->client->privateDownloadUrl($reference->publicId, $reference->format, [
            'type' => 'authenticated',
            'resource_type' => 'raw',
            'attachment' => true,
            'expires_at' => now()->addSeconds((int) config('services.cloudinary.download_ttl', 300))->timestamp,
        ]);
    }

    private function assertTrustedReference(SchoolFileReference $reference): void
    {
        if (TenantContext::require()->schoolPublicId !== $reference->schoolId) {
            throw new UnsafeFileUpload(FileScanResult::Unavailable);
        }
    }
}
