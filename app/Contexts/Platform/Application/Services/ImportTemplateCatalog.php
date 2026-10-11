<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class ImportTemplateCatalog
{
    /**
     * @return array<string, array{key: string, name: string, version: string, formats: list<string>, fields: list<array{name: string, required: bool, description: string, example: string}>}>
     */
    public function all(): array
    {
        return [
            'students' => [
                'key' => 'students',
                'name' => 'Student admissions',
                'version' => 'v1',
                'formats' => ['csv', 'xlsx'],
                'fields' => [
                    ['name' => 'student_number', 'required' => true, 'description' => 'The school-local admission or student number.', 'example' => 'STU-001'],
                    ['name' => 'display_name', 'required' => true, 'description' => 'The student name shown in the school registry.', 'example' => 'Fictional Learner'],
                    ['name' => 'admission_date', 'required' => false, 'description' => 'The admission date in YYYY-MM-DD format.', 'example' => '2026-09-01'],
                    ['name' => 'metadata', 'required' => false, 'description' => 'Optional bounded administrative metadata for later validation; do not include secrets or contacts.', 'example' => 'cohort-a'],
                ],
            ],
            'guardians' => [
                'key' => 'guardians',
                'name' => 'Guardian relationships',
                'version' => 'v1',
                'formats' => ['csv', 'xlsx'],
                'fields' => [
                    ['name' => 'student_number', 'required' => true, 'description' => 'The school-local student number to use when the import is later validated.', 'example' => 'STU-001'],
                    ['name' => 'guardian_contact', 'required' => true, 'description' => 'An email address or international phone contact for later identity matching; no identity is created by this template.', 'example' => 'guardian@example.test'],
                    ['name' => 'guardian_display_name', 'required' => true, 'description' => 'The bounded display name for the guardian relationship.', 'example' => 'Fictional Guardian'],
                    ['name' => 'relationship_type', 'required' => true, 'description' => 'The approved relationship type, such as parent, guardian or grandparent.', 'example' => 'guardian'],
                ],
            ],
            'staff' => [
                'key' => 'staff',
                'name' => 'Staff profiles',
                'version' => 'v1',
                'formats' => ['csv', 'xlsx'],
                'fields' => [
                    ['name' => 'staff_number', 'required' => true, 'description' => 'The school-local staff number.', 'example' => 'STF-001'],
                    ['name' => 'membership_contact', 'required' => true, 'description' => 'An existing identity contact for later membership matching; no identity or membership is created by this template.', 'example' => 'teacher@example.test'],
                    ['name' => 'legal_name', 'required' => true, 'description' => 'The bounded legal name for the staff profile.', 'example' => 'Fictional Teacher'],
                    ['name' => 'preferred_name', 'required' => false, 'description' => 'The name used in school-facing displays.', 'example' => 'Fictional Teacher'],
                    ['name' => 'job_title', 'required' => false, 'description' => 'The staff job title.', 'example' => 'Class Teacher'],
                    ['name' => 'department', 'required' => false, 'description' => 'The school-defined department label.', 'example' => 'Primary'],
                    ['name' => 'employment_status', 'required' => false, 'description' => 'A proposed employment state for later validation; the server controls lifecycle state.', 'example' => 'pending'],
                ],
            ],
            'historical_academic_results' => [
                'key' => 'historical_academic_results',
                'name' => 'Historical academic results',
                'version' => 'v1',
                'formats' => ['csv', 'xlsx'],
                'fields' => [
                    ['name' => 'student_number', 'required' => true, 'description' => 'The school-local student number for an existing admitted student.', 'example' => 'STU-001'],
                    ['name' => 'academic_session', 'required' => true, 'description' => 'The public identifier of the existing academic session.', 'example' => '0192f8d2-7b2f-72e8-9a00-123456789001'],
                    ['name' => 'academic_term', 'required' => true, 'description' => 'The public identifier of the existing academic term.', 'example' => '0192f8d2-7b2f-72e8-9a00-123456789002'],
                    ['name' => 'subject_offering', 'required' => true, 'description' => 'The public identifier of the existing subject offering.', 'example' => '0192f8d2-7b2f-72e8-9a00-123456789003'],
                    ['name' => 'policy_version', 'required' => false, 'description' => 'The public identifier of the historical assessment policy version, when available.', 'example' => '0192f8d2-7b2f-72e8-9a00-123456789004'],
                    ['name' => 'score', 'required' => true, 'description' => 'The imported historical score. It is retained as unverified provenance and is not calculated or published.', 'example' => '72.50'],
                    ['name' => 'max_marks', 'required' => true, 'description' => 'The maximum marks used by the historical source record.', 'example' => '100'],
                    ['name' => 'grade', 'required' => false, 'description' => 'The source grade label, if supplied.', 'example' => 'B'],
                    ['name' => 'remark', 'required' => false, 'description' => 'The source remark, if supplied.', 'example' => 'Historical record'],
                    ['name' => 'provenance_reference', 'required' => true, 'description' => 'A bounded reference to the source document or school archive; do not include a private URL or contact.', 'example' => 'ARCHIVE-2025-001'],
                ],
            ],
            'financial_opening_balances' => [
                'key' => 'financial_opening_balances',
                'name' => 'Financial opening balances',
                'version' => 'v1',
                'formats' => ['csv', 'xlsx'],
                'fields' => [
                    ['name' => 'student_number', 'required' => true, 'description' => 'The school-local student number for an existing active student.', 'example' => 'STU-001'],
                    ['name' => 'opening_balance', 'required' => true, 'description' => 'The historical outstanding amount at the as-of date. This is archived as an opening position, not a payment or invoice.', 'example' => '35000.00'],
                    ['name' => 'currency', 'required' => true, 'description' => 'The three-letter currency code for the opening balance.', 'example' => 'NGN'],
                    ['name' => 'as_of_date', 'required' => true, 'description' => 'The date on which the opening balance was observed, in YYYY-MM-DD format.', 'example' => '2026-08-31'],
                    ['name' => 'provenance_reference', 'required' => true, 'description' => 'A bounded reference to the source financial archive; do not include a private URL or contact.', 'example' => 'FIN-ARCHIVE-2025-001'],
                ],
            ],
        ];
    }

    /** @return array{key: string, name: string, version: string, formats: list<string>, fields: list<array{name: string, required: bool, description: string, example: string}>} */
    public function get(string $key): array
    {
        $template = $this->all()[$key] ?? null;
        if (! is_array($template)) {
            throw new ModelNotFoundException;
        }

        return $template;
    }

    /** @param array{fields: list<array{name: string, required: bool, description: string, example: string}>} $template
     * @return list<string>
     */
    public function headers(array $template): array
    {
        return array_map(static fn (array $field): string => $field['name'], $template['fields']);
    }

    /** @param array{fields: list<array{name: string, required: bool, description: string, example: string}>} $template
     * @return list<string>
     */
    public function example(array $template): array
    {
        return array_map(static fn (array $field): string => (string) $field['example'], $template['fields']);
    }

    /** @param array{key: string, version: string} $template */
    public function filename(array $template, string $format): string
    {
        return Str::slug($template['key'].'-'.$template['version']).'.'.$format;
    }
}
