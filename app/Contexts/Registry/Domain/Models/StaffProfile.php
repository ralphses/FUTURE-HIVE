<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\SchoolMembership;
use App\Support\Tenancy\TenantScoped;
use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'school_membership_id', 'staff_number', 'legal_name', 'preferred_name', 'job_title', 'department', 'employment_status', 'employment_start_date', 'employment_end_date', 'status_reason', 'metadata'])]
#[Hidden(['id', 'school_id', 'school_membership_id', 'status_reason', 'metadata'])]
final class StaffProfile extends Model
{
    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): StaffProfileFactory
    {
        return StaffProfileFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $profile): void {
            $profile->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<SchoolMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(SchoolMembership::class, 'school_membership_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employment_start_date' => 'date',
            'employment_end_date' => 'date',
            'metadata' => 'array',
        ];
    }
}
