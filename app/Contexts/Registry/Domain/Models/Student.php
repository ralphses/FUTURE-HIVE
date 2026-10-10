<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_number', 'display_name', 'admission_date', 'status', 'metadata'])]
#[Hidden(['id', 'school_id', 'metadata'])]
final class Student extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $student): void {
            $student->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['admission_date' => 'date', 'metadata' => 'array'];
    }

    /** @return HasOne<StudentProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasMany<StudentDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    /** @return HasMany<StudentGuardianRelationship, $this> */
    public function guardianRelationships(): HasMany
    {
        return $this->hasMany(StudentGuardianRelationship::class);
    }
}
