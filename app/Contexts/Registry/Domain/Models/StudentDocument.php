<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable([
    'public_id', 'school_id', 'student_id', 'category', 'original_filename', 'mime_type', 'format',
    'size', 'checksum', 'cloudinary_public_id', 'cloudinary_resource_type', 'status', 'uploaded_by',
    'uploaded_at', 'revoked_at', 'revoked_reason',
])]
#[Hidden(['id', 'school_id', 'student_id', 'checksum', 'cloudinary_public_id', 'cloudinary_resource_type', 'uploaded_by', 'revoked_reason'])]
final class StudentDocument extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $document): void {
            $document->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
