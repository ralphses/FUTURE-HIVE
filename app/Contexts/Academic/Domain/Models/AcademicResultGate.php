<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_run_id', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'published_by', 'published_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_run_id', 'reviewed_by', 'published_by', 'request_id'])]
final class AcademicResultGate extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $gate): void {
            $gate->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<AcademicResultRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AcademicResultRun::class, 'academic_result_run_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'reviewed_by');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'published_by');
    }
}
