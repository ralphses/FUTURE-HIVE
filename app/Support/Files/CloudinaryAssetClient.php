<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Http\UploadedFile;

interface CloudinaryAssetClient
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function upload(UploadedFile $file, array $options): array;

    /**
     * @param  array<string, mixed>  $options
     */
    public function privateDownloadUrl(string $publicId, string $format, array $options): string;
}
