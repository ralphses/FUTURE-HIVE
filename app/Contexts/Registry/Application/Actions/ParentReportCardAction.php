<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplate;
use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplateField;
use App\Contexts\Academic\Domain\Models\AcademicReportCardTemplateSection;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshot;
use App\Contexts\Academic\Domain\Models\AcademicResultSnapshotEntry;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class ParentReportCardAction
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function index(UserIdentity $actor): LengthAwarePaginator
    {
        $paginator = $this->relationships($actor)->paginate(ApiResponse::perPage(request()->integer('per_page')));
        $items = $paginator->getCollection()->map(fn (object $row): array => $this->reportCard($row))->values();
        $paginator->setCollection($items);
        $this->auditRead($actor, $items->count());

        return $paginator;
    }

    /** @return array{report_card: array<string, mixed>} */
    public function child(UserIdentity $actor, string $student): array
    {
        $row = $this->relationships($actor)->where('students.public_id', $student)->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        $this->auditRead($actor, 1);

        return ['report_card' => $this->reportCard($row)];
    }

    private function relationships(UserIdentity $actor): Builder
    {
        return DB::table('student_guardian_relationships')
            ->join('guardian_profiles', 'guardian_profiles.id', '=', 'student_guardian_relationships.guardian_profile_id')
            ->join('students', 'students.id', '=', 'student_guardian_relationships.student_id')
            ->join('schools', 'schools.id', '=', 'student_guardian_relationships.school_id')
            ->where('guardian_profiles.user_id', $actor->getKey())
            ->where('student_guardian_relationships.status', 'active')
            ->whereNotNull('student_guardian_relationships.verified_at')
            ->whereNull('student_guardian_relationships.revoked_at')
            ->where('students.status', 'active')
            ->where('schools.status', 'active')
            ->select([
                'students.id as student_id',
                'students.public_id as student_public_id',
                'students.student_number',
                'students.display_name',
                'student_guardian_relationships.relationship_type',
                'student_guardian_relationships.school_id',
                'schools.public_id as school_public_id',
                'schools.name as school_name',
            ])
            ->orderBy('schools.name')
            ->orderBy('students.display_name')
            ->orderBy('students.id');
    }

    /** @return array<string, mixed> */
    private function reportCard(object $row): array
    {
        $snapshot = $this->snapshot($row);
        if (! $snapshot instanceof AcademicResultSnapshot) {
            return [
                'student' => [
                    'id' => (string) $row->student_public_id,
                    'student_number' => (string) $row->student_number,
                    'display_name' => (string) $row->display_name,
                ],
                'school' => ['id' => (string) $row->school_public_id, 'name' => (string) $row->school_name],
                'relationship_type' => (string) $row->relationship_type,
                'availability' => ['status' => 'template_unavailable', 'snapshot' => null, 'template' => null],
                'sections' => [],
            ];
        }
        $template = AcademicReportCardTemplate::query()
            ->where('school_id', $row->school_id)
            ->where('academic_session_id', $snapshot->academic_session_id)
            ->where('academic_term_id', $snapshot->academic_term_id)
            ->where('academic_result_snapshot_id', $snapshot->getKey())
            ->where('status', 'active')
            ->with('sections.fields')
            ->first();

        return [
            'student' => [
                'id' => (string) $row->student_public_id,
                'student_number' => (string) $row->student_number,
                'display_name' => (string) $row->display_name,
            ],
            'school' => ['id' => (string) $row->school_public_id, 'name' => (string) $row->school_name],
            'relationship_type' => (string) $row->relationship_type,
            'availability' => [
                'status' => $template === null ? 'template_unavailable' : 'available',
                'snapshot' => $this->snapshotData($snapshot),
                'template' => $template === null ? null : $this->templateData($template),
            ],
            'sections' => $template === null ? [] : $this->sections($template, $snapshot, $row),
        ];
    }

    private function snapshot(object $row): ?AcademicResultSnapshot
    {
        return AcademicResultSnapshot::query()
            ->where('school_id', $row->school_id)
            ->where('status', 'published')
            ->whereDoesntHave('supersedingSnapshots', fn ($query) => $query->where('status', 'published'))
            ->whereHas('gate', fn ($query) => $query->where('status', 'published'))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function snapshotData(AcademicResultSnapshot $snapshot): array
    {
        return [
            'id' => (string) $snapshot->public_id,
            'version' => (int) $snapshot->snapshot_version,
            'published_at' => CarbonImmutable::parse((string) $snapshot->published_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function templateData(AcademicReportCardTemplate $template): array
    {
        return [
            'id' => (string) $template->public_id,
            'version' => (int) $template->version,
            'name' => (string) $template->name,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sections(AcademicReportCardTemplate $template, AcademicResultSnapshot $snapshot, object $row): array
    {
        $entries = $this->entries($snapshot, $row);

        return $template->sections->filter(fn (AcademicReportCardTemplateSection $section): bool => $section->is_visible)
            ->map(fn (AcademicReportCardTemplateSection $section): array => [
                'key' => (string) $section->section_key,
                'label' => (string) $section->label,
                'sequence' => (int) $section->sequence,
                'fields' => $section->fields->filter(fn (AcademicReportCardTemplateField $field): bool => $field->is_visible)
                    ->map(fn (AcademicReportCardTemplateField $field): array => [
                        'key' => (string) $field->field_key,
                        'label' => (string) $field->label,
                        'type' => (string) $field->field_type,
                        'sequence' => (int) $field->sequence,
                        'value' => $this->fieldValue($field, $row, $entries),
                    ])->values()->all(),
            ])->values()->all();
    }

    /** @return Collection<int, AcademicResultSnapshotEntry> */
    private function entries(AcademicResultSnapshot $snapshot, object $row): Collection
    {
        return AcademicResultSnapshotEntry::query()
            ->where('school_id', $row->school_id)
            ->where('academic_result_snapshot_id', $snapshot->getKey())
            ->where('student_id', $row->student_id)
            ->whereNull('error_code')
            ->with(['offering.subject'])
            ->orderBy('academic_subject_offering_id')
            ->get();
    }

    /** @param Collection<int, AcademicResultSnapshotEntry> $entries */
    private function fieldValue(AcademicReportCardTemplateField $field, object $row, Collection $entries): mixed
    {
        return match ($field->field_key) {
            'student_name' => (string) $row->display_name,
            'student_number' => (string) $row->student_number,
            'subject_results', 'results' => $entries->map(fn (AcademicResultSnapshotEntry $entry): array => $this->entryData($entry))->values()->all(),
            'total_score' => $entries->sum(fn (AcademicResultSnapshotEntry $entry): float => (float) ($entry->total_score ?? 0)),
            'total_max_marks' => $entries->sum(fn (AcademicResultSnapshotEntry $entry): float => (float) ($entry->total_max_marks ?? 0)),
            'average_percentage', 'percentage' => $entries->isEmpty() ? null : round((float) $entries->avg(fn (AcademicResultSnapshotEntry $entry): float => (float) ($entry->percentage ?? 0)), 2),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function entryData(AcademicResultSnapshotEntry $entry): array
    {
        return [
            'offering_id' => (string) $entry->offering?->public_id,
            'subject_name' => (string) $entry->offering?->subject?->name,
            'status' => (string) $entry->status,
            'total_score' => $entry->total_score === null ? null : (float) $entry->total_score,
            'total_max_marks' => $entry->total_max_marks === null ? null : (float) $entry->total_max_marks,
            'percentage' => $entry->percentage === null ? null : (float) $entry->percentage,
            'grade' => $entry->grade,
            'grade_label' => $entry->grade_label,
            'is_passing' => $entry->is_passing,
            'remark' => $entry->remark,
            'error_code' => $entry->error_code,
        ];
    }

    private function auditRead(UserIdentity $actor, int $resultCount): void
    {
        $this->audit->execute(new AuditEventData(
            action: 'parent.report_card.viewed',
            subjectType: 'parent_report_card_projection',
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            metadata: ['result_count' => $resultCount, 'caller_type' => 'verified_guardian'],
        ));
    }
}
