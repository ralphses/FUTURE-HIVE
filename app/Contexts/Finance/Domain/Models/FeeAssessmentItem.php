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
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'fee_assessment_run_id', 'fee_invoice_id', 'student_id', 'student_enrollment_id', 'status', 'total_minor_units'])]
#[Hidden(['id', 'school_id', 'fee_assessment_run_id', 'fee_invoice_id', 'student_id', 'student_enrollment_id'])]
final class FeeAssessmentItem extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['total_minor_units' => 'integer'];
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

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
