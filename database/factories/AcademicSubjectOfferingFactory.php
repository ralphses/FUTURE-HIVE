<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicSubjectOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicSubjectOffering> */
final class AcademicSubjectOfferingFactory extends Factory
{
    protected $model = AcademicSubjectOffering::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['display_order' => 1, 'status' => 'active'];
    }
}
