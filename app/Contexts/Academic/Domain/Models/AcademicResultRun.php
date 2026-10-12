<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'academic_session_id', 'academic_term_id', 'status', 'source_fingerprint', 'computed_count', 'not_computable_count', 'created_by', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'created_by', 'request_id', 'source_fingerprint'])]
final class AcademicResultRun extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['computed_count' => 'integer', 'not_computable_count' => 'integer'];
    }

    /** @return HasMany<AcademicResultEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AcademicResultEntry::class);
    }
}
