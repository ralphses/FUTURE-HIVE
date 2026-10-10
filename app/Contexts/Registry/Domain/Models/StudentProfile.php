<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['school_id', 'student_id', 'legal_name', 'preferred_name', 'date_of_birth', 'gender', 'notes'])]
#[Hidden(['id', 'school_id', 'student_id', 'notes'])]
final class StudentProfile extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
