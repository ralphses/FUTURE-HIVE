<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Contexts\Registry\Domain\Models\GuardianProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_invoice_id', 'student_id', 'student_enrollment_id', 'guardian_profile_id', 'recipient_public_id', 'recipient_reference_hash', 'reminder_type', 'period_key', 'amount_minor_units', 'currency', 'due_date', 'status', 'created_by', 'cancelled_by', 'queued_at', 'sent_at', 'failed_at', 'cancelled_at', 'failure_code', 'request_id'])]
#[Hidden(['id', 'school_id', 'fee_invoice_id', 'student_id', 'student_enrollment_id', 'guardian_profile_id', 'recipient_reference_hash', 'created_by', 'cancelled_by', 'request_id'])]
final class FinanceReminder extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $reminder): void {
            $reminder->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['amount_minor_units' => 'integer', 'due_date' => 'date', 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<StudentEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    /** @return BelongsTo<GuardianProfile, $this> */
    public function guardianProfile(): BelongsTo
    {
        return $this->belongsTo(GuardianProfile::class);
    }
}
