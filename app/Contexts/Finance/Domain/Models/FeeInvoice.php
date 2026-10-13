<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentEnrollment;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'invoice_number', 'school_id', 'fee_assessment_run_id', 'student_id', 'student_enrollment_id', 'currency', 'total_minor_units', 'due_date', 'status', 'issued_by', 'issued_at'])]
#[Hidden(['id', 'school_id', 'fee_assessment_run_id', 'student_id', 'student_enrollment_id', 'issued_by'])]
final class FeeInvoice extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $invoice): void {
            $invoice->public_id ??= (string) Str::uuid7();
            $invoice->invoice_number ??= 'INV-'.strtoupper(str_replace('-', '', $invoice->public_id));
        });
    }

    protected function casts(): array
    {
        return ['total_minor_units' => 'integer', 'due_date' => 'date', 'issued_at' => 'datetime'];
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

    /** @return BelongsTo<FeeAssessmentRun, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(FeeAssessmentRun::class, 'fee_assessment_run_id');
    }

    /** @return HasMany<FeeInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FeeInvoiceLine::class)->orderBy('sequence');
    }

    /** @return HasMany<FeeInvoiceAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(FeeInvoiceAdjustment::class);
    }
}
