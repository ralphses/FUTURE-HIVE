<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'report_type', 'format', 'academic_session_id', 'academic_term_id', 'from_date', 'to_date', 'frequency', 'status', 'next_run_at', 'created_by', 'updated_by', 'idempotency_key_hash', 'request_id'])]
#[Hidden(['id', 'school_id', 'academic_session_id', 'academic_term_id', 'created_by', 'updated_by', 'idempotency_key_hash', 'request_id'])]
final class OperationalReportSchedule extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $schedule): void {
            $schedule->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'next_run_at' => 'datetime'];
    }
}
