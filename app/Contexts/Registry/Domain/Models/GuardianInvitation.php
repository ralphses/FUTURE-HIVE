<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_id', 'guardian_profile_id', 'relationship_id', 'code_hash', 'expires_at', 'attempts', 'max_attempts', 'consumed_at', 'revoked_at', 'revocation_reason', 'request_ip_hash', 'request_user_agent_hash', 'created_by'])]
#[Hidden(['id', 'school_id', 'student_id', 'guardian_profile_id', 'relationship_id', 'code_hash', 'request_ip_hash', 'request_user_agent_hash', 'created_by'])]
final class GuardianInvitation extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $invitation): void {
            $invitation->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
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

    /** @return BelongsTo<StudentGuardianRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(StudentGuardianRelationship::class, 'relationship_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }
}
