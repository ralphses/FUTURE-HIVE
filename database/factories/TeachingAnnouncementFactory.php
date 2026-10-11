<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contexts\Academic\Domain\Models\TeachingAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeachingAnnouncement> */
final class TeachingAnnouncementFactory extends Factory
{
    protected $model = TeachingAnnouncement::class;

    public function definition(): array
    {
        return ['title' => 'Fictional class update', 'body' => 'This fictional announcement is for testing only.', 'status' => 'draft'];
    }
}
