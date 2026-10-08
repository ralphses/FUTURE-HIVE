<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicSubject;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicSubject> */
final class AcademicSubjectFactory extends Factory
{
    protected $model = AcademicSubject::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Fictional Studies',
            'code' => 'FST',
            'classification' => 'elective',
            'status' => 'active',
        ];
    }
}
