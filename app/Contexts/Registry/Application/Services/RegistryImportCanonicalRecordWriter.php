<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Services;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Contracts\ImportCanonicalRecordWriter;
use App\Contexts\Registry\Application\Actions\StudentAdmissionAction;
use App\Contexts\Registry\Domain\Models\StaffProfile;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentGuardianRelationship;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class RegistryImportCanonicalRecordWriter implements ImportCanonicalRecordWriter
{
    public function __construct(private readonly StudentAdmissionAction $students) {}

    /** @param array{student_number: string, display_name: string, admission_date?: string|null, metadata?: array<string, mixed>|null} $values */
    public function createStudent(Authenticatable $actor, array $values): string
    {
        if (! $actor instanceof UserIdentity) {
            throw new ModelNotFoundException;
        }

        $student = $this->students->create($actor, TenantContext::require()->schoolPublicId, $values);

        return (string) $student['id'];
    }

    public function verifyCandidate(string $template, string $candidate): string
    {
        if ($candidate === '') {
            throw new ModelNotFoundException;
        }

        return match ($template) {
            'students' => (string) Student::query()->where('public_id', $candidate)->value('public_id') ?: throw new ModelNotFoundException,
            'guardians' => (string) StudentGuardianRelationship::query()->where('public_id', $candidate)->value('public_id') ?: throw new ModelNotFoundException,
            'staff' => (string) StaffProfile::query()->where('public_id', $candidate)->value('public_id') ?: throw new ModelNotFoundException,
            default => throw new ModelNotFoundException,
        };
    }
}
