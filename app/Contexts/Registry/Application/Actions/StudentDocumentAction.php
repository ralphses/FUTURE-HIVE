<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Application\Actions;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Registry\Domain\Models\Student;
use App\Contexts\Registry\Domain\Models\StudentDocument;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Files\SchoolFileReference;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentDocumentAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly CloudinaryAssetStore $files,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function index(UserIdentity $actor, string $school, string $student): array
    {
        $this->authorize($actor, $school, 'students.documents.read');
        $studentRecord = $this->findStudent($student);

        return ['items' => StudentDocument::query()->where('student_id', $studentRecord->getKey())->where('status', 'active')->latest()->get()->map(fn (StudentDocument $document): array => $this->data($document))->all()];
    }

    /** @return array<string, mixed> */
    public function upload(UserIdentity $actor, string $school, string $student, string $category, UploadedFile $file): array
    {
        $this->authorize($actor, $school, 'students.documents.manage');
        $studentRecord = $this->findStudent($student);
        $reference = $this->files->store($file, $school);
        $checksum = hash_file('sha256', (string) $file->getRealPath());
        if ($checksum === false) {
            throw ValidationException::withMessages(['document' => ['The document could not be fingerprinted.']]);
        }

        $document = StudentDocument::query()->create([
            'student_id' => $studentRecord->getKey(),
            'category' => $category,
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'format' => $reference->format,
            'size' => (int) $file->getSize(),
            'checksum' => $checksum,
            'cloudinary_public_id' => $reference->publicId,
            'cloudinary_resource_type' => $reference->resourceType,
            'status' => 'active',
            'uploaded_by' => (int) $actor->getAuthIdentifier(),
            'uploaded_at' => now(),
        ]);
        $this->audit->execute(new AuditEventData(action: 'student.document_uploaded', subjectType: 'student_document', subjectPublicId: (string) $document->public_id, schoolId: TenantContext::require()->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'students.documents.manage'], metadata: ['category' => $category, 'format' => $reference->format], requiresSchoolContext: true));

        return $this->data($document);
    }

    /** @return array<string, mixed> */
    public function download(UserIdentity $actor, string $school, string $student, string $document): array
    {
        $this->authorize($actor, $school, 'students.documents.read');
        $studentRecord = $this->findStudent($student);
        $record = StudentDocument::query()->where('student_id', $studentRecord->getKey())->where('public_id', $document)->where('status', 'active')->first();
        if (! $record instanceof StudentDocument) {
            throw new ModelNotFoundException;
        }
        $url = $this->files->temporaryDownloadUrl(new SchoolFileReference((string) TenantContext::require()->schoolPublicId, (string) $record->cloudinary_public_id, (string) $record->format, (string) $record->cloudinary_resource_type));
        $this->audit->execute(new AuditEventData(action: 'student.document_download_requested', subjectType: 'student_document', subjectPublicId: (string) $record->public_id, schoolId: TenantContext::require()->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'students.documents.read'], requiresSchoolContext: true));

        return ['id' => (string) $record->public_id, 'download_url' => $url, 'expires_in' => (int) config('services.cloudinary.download_ttl', 300)];
    }

    /** @return array<string, mixed> */
    public function revoke(UserIdentity $actor, string $school, string $student, string $document): array
    {
        $this->authorize($actor, $school, 'students.documents.manage');
        $studentRecord = $this->findStudent($student);

        return DB::transaction(function () use ($actor, $studentRecord, $document): array {
            $record = StudentDocument::query()->where('student_id', $studentRecord->getKey())->where('public_id', $document)->lockForUpdate()->first();
            if (! $record instanceof StudentDocument) {
                throw new ModelNotFoundException;
            }
            if ($record->status === 'active') {
                $record->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'revoked_by_authorized_school_user']);
                $this->audit->execute(new AuditEventData(action: 'student.document_revoked', subjectType: 'student_document', subjectPublicId: (string) $record->public_id, schoolId: TenantContext::require()->schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), authorizationContext: ['permission' => 'students.documents.manage'], stateTransition: ['from' => 'active', 'to' => 'revoked'], requiresSchoolContext: true));
            }

            return $this->data($record);
        });
    }

    private function authorize(UserIdentity $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function findStudent(string $publicId): Student
    {
        $student = Student::query()->where('public_id', $publicId)->first();
        if (! $student instanceof Student) {
            throw new ModelNotFoundException;
        }

        return $student;
    }

    /** @return array<string, mixed> */
    private function data(StudentDocument $document): array
    {
        $rawUploadedAt = $document->getAttribute('uploaded_at');
        $uploadedAt = $rawUploadedAt === null ? null : CarbonImmutable::parse((string) $rawUploadedAt)->toIso8601String();
        $downloadUrl = null;
        if ($document->status === 'active') {
            $downloadUrl = $this->files->temporaryDownloadUrl(new SchoolFileReference(
                schoolId: TenantContext::require()->schoolPublicId,
                publicId: (string) $document->cloudinary_public_id,
                format: (string) $document->format,
                resourceType: (string) $document->cloudinary_resource_type,
            ));
        }

        return [
            'id' => (string) $document->public_id,
            'category' => (string) $document->category,
            'original_filename' => (string) $document->original_filename,
            'mime_type' => (string) $document->mime_type,
            'format' => (string) $document->format,
            'size' => (int) $document->size,
            'status' => (string) $document->status,
            'uploaded_at' => $uploadedAt,
            'download_url' => $downloadUrl,
        ];
    }
}
