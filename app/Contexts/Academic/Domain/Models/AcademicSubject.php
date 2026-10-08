<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicSubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'name', 'code', 'classification', 'status', 'public_id'])]
#[Hidden(['id'])]
final class AcademicSubject extends Model
{
    /** @use HasFactory<AcademicSubjectFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicSubjectFactory
    {
        return AcademicSubjectFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $subject): void {
            $subject->public_id ??= (string) Str::uuid7();
        });
    }
}
