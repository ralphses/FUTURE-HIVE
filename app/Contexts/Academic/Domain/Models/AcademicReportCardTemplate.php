<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_snapshot_id', 'version', 'name', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'academic_result_snapshot_id', 'created_by', 'activated_by', 'retired_by', 'request_id'])]
final class AcademicReportCardTemplate extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $template): void {
            $template->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    /** @return HasMany<AcademicReportCardTemplateSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(AcademicReportCardTemplateSection::class)->orderBy('sequence');
    }

    /** @return BelongsTo<AcademicResultSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AcademicResultSnapshot::class, 'academic_result_snapshot_id');
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'academic_term_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }
}
