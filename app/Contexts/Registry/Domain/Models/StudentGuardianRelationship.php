<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_id', 'guardian_profile_id', 'relationship_type', 'status', 'verified_at', 'revoked_at', 'revocation_reason', 'created_by', 'revoked_by'])]
#[Hidden(['id', 'school_id', 'student_id', 'guardian_profile_id', 'created_by', 'revoked_by'])]
final class StudentGuardianRelationship extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $relationship): void {
            $relationship->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<GuardianProfile, $this> */
    public function guardianProfile(): BelongsTo
    {
        return $this->belongsTo(GuardianProfile::class);
    }
}
