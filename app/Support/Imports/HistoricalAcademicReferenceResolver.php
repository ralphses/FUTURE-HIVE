<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Contexts\Academic\Domain\Models\AcademicAssessmentPolicyVersion;
use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Registry\Domain\Models\Student;

final class HistoricalAcademicReferenceResolver
{
    /**
     * @param  array<string, string>  $values
     * @return array{student_id:int|null, academic_session_id:int|null, academic_term_id:int|null, academic_subject_offering_id:int|null, academic_assessment_policy_version_id:int|null, score:string|null, max_marks:string|null, grade:string|null, remark:string|null, provenance_reference:string|null, error_code:string|null}
     */
    public function resolve(array $values): array
    {
        $student = Student::query()->where('student_number', $values['student_number'] ?? '')->first();
        $session = AcademicSession::query()->where('public_id', $values['academic_session'] ?? '')->first();
        $term = AcademicTerm::query()->where('public_id', $values['academic_term'] ?? '')->first();
        $offering = AcademicSubjectOffering::query()->where('public_id', $values['subject_offering'] ?? '')->first();
        $policy = ! empty($values['policy_version']) ? AcademicAssessmentPolicyVersion::query()->where('public_id', $values['policy_version'])->first() : null;
        $error = null;

        if (! $student instanceof Student || ! $session instanceof AcademicSession || ! $term instanceof AcademicTerm || ! $offering instanceof AcademicSubjectOffering || (! empty($values['policy_version']) && ! $policy instanceof AcademicAssessmentPolicyVersion)) {
            $error = 'reference_unavailable';
        } elseif ((int) $term->academic_session_id !== (int) $session->getKey() || (int) $offering->academic_term_id !== (int) $term->getKey() || ($policy !== null && ((int) $policy->academic_subject_offering_id !== (int) $offering->getKey() || (int) $policy->academic_term_id !== (int) $term->getKey()))) {
            $error = 'reference_mismatch';
        } elseif ((float) ($values['score'] ?? 0) > (float) ($values['max_marks'] ?? 0) || (float) ($values['max_marks'] ?? 0) <= 0) {
            $error = 'invalid_marks';
        }

        return [
            'student_id' => $student?->getKey(),
            'academic_session_id' => $session?->getKey(),
            'academic_term_id' => $term?->getKey(),
            'academic_subject_offering_id' => $offering?->getKey(),
            'academic_assessment_policy_version_id' => $policy?->getKey(),
            'score' => $values['score'] ?? null,
            'max_marks' => $values['max_marks'] ?? null,
            'grade' => $values['grade'] ?? null,
            'remark' => $values['remark'] ?? null,
            'provenance_reference' => $values['provenance_reference'] ?? null,
            'error_code' => $error,
        ];
    }
}
