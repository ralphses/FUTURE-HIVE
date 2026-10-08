<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\AcademicClassArm;
use App\Contexts\Academic\Domain\Models\AcademicLevel;
use App\Contexts\Academic\Domain\Models\AcademicSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicClassArm> */
final class AcademicClassArmFactory extends Factory
{
    protected $model = AcademicClassArm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $level = AcademicLevel::factory()->create();
        $section = AcademicSection::factory()->for($level)->create();

        return [
            'school_id' => $level->school_id,
            'academic_level_id' => $level->id,
            'academic_section_id' => $section->id,
            'name' => 'Blue Arm',
            'code' => 'BLUE',
            'capacity' => 30,
            'status' => 'active',
        ];
    }
}
