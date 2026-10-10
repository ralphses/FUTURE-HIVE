<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

final class StudentSearchExportAction
{
    private const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function search(UserIdentity $actor, string $school, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, $school, 'students.read');

        $paginator = $this->query($filters)
            ->with(['enrollments' => fn (Relation $query): mixed => $query->where('status', 'active')->with(['term', 'classArm'])])
            ->orderBy('display_name')
            ->orderBy('student_number')
            ->paginate(ApiResponse::perPage(isset($filters['per_page']) ? (int) $filters['per_page'] : null));

        $paginator->setCollection($paginator->getCollection()->map(fn (Student $student): array => $this->data($student)));

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function export(UserIdentity $actor, string $school, array $filters): array
    {
        $this->authorize($actor, $school, 'students.export');

        $students = $this->query($filters)
            ->with(['enrollments' => fn (Relation $query): mixed => $query->where('status', 'active')->with(['term', 'classArm'])])
            ->orderBy('display_name')
            ->orderBy('student_number')
            ->limit(self::MAX_EXPORT_ROWS + 1)
            ->get();

        if ($students->count() > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages([
                'filters' => 'The export is too large. Narrow the filters and try again.',
            ]);
        }

        $this->audit->execute(new AuditEventData(
            action: 'student.exported',
            subjectType: 'student_search',
            schoolId: TenantContext::require()->schoolId,
            actorId: $actor->getKey(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'students.export'],
            metadata: ['format' => $filters['format'], 'row_count' => $students->count()],
            requiresSchoolContext: true,
        ));

        $rows = [];
        foreach ($students as $student) {
            $rows[] = $this->data($student);
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Student>
     */
    private function query(array $filters): Builder
    {
        $query = Student::query();

        if (isset($filters['q']) && $filters['q'] !== '') {
            $term = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('student_number', 'like', '%'.$term.'%')
                    ->orWhere('display_name', 'like', '%'.$term.'%');
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['term_id'])) {
            $query->whereHas('enrollments', fn (Builder $builder): Builder => $builder
                ->where('status', 'active')
                ->whereHas('term', fn (Builder $term): Builder => $term->where('public_id', $filters['term_id'])));
        }

        if (isset($filters['class_arm_id'])) {
            $query->whereHas('enrollments', fn (Builder $builder): Builder => $builder
                ->where('status', 'active')
                ->whereHas('classArm', fn (Builder $arm): Builder => $arm->where('public_id', $filters['class_arm_id'])));
        }

        return $query;
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    /** @return array<string, mixed> */
    private function data(Student $student): array
    {
        $enrollment = $student->enrollments->first();

        return [
            'id' => (string) $student->public_id,
            'student_number' => (string) $student->student_number,
            'display_name' => (string) $student->display_name,
            'status' => (string) $student->status,
            'admission_date' => $this->admissionDate($student),
            'current_placement' => $enrollment === null ? null : [
                'term' => (string) $enrollment->term?->name,
                'class_arm' => (string) $enrollment->classArm?->name,
            ],
        ];
    }

    private function admissionDate(Student $student): ?string
    {
        $date = $student->getAttribute('admission_date');

        return $date instanceof CarbonInterface ? $date->toDateString() : ($date === null ? null : (string) $date);
    }
}
