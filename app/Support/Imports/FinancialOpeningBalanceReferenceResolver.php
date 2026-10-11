<?php

declare(strict_types=1);

namespace App\Support\Imports;

use App\Contexts\Registry\Domain\Models\Student;

final class FinancialOpeningBalanceReferenceResolver
{
    /**
     * @param  array<string, string>  $values
     * @return array{student_id:int|null, opening_balance:string|null, currency:string|null, as_of_date:string|null, provenance_reference:string|null, error_code:string|null}
     */
    public function resolve(array $values): array
    {
        $student = Student::query()->where('student_number', $values['student_number'] ?? '')->where('status', 'active')->first();
        $error = $student instanceof Student ? null : 'student_unavailable';

        return [
            'student_id' => $student?->getKey(),
            'opening_balance' => $values['opening_balance'] ?? null,
            'currency' => $values['currency'] ?? null,
            'as_of_date' => $values['as_of_date'] ?? null,
            'provenance_reference' => $values['provenance_reference'] ?? null,
            'error_code' => $error,
        ];
    }
}
