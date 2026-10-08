<?php

declare(strict_types=1);

namespace App\Support\Files;

use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class CloudinarySdkClient implements CloudinaryAssetClient
{
    public function __construct(private readonly UploadApi $uploadApi = new UploadApi) {}

    public function upload(UploadedFile $file, array $options): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('The uploaded file is not available for scanning or storage.');
        }

        return $this->uploadApi->upload($path, $options)->getArrayCopy();
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        return $this->uploadApi->privateDownloadUrl($publicId, $format, $options);
    }
}
