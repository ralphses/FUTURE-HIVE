<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Application\Actions;

use App\Contexts\Academic\Domain\Models\AcademicSession;
use App\Contexts\Academic\Domain\Models\AcademicTerm;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcademicPeriodAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function sessions(UserIdentity $actor, string $schoolPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.read');

        return ['items' => AcademicSession::query()->orderBy('start_date')->orderBy('name')->get()->map(fn (AcademicSession $session): array => $this->sessionData($session))->all()];
    }

    /** @return array<string, mixed> */
    public function session(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.read');

        return $this->sessionData($this->findSession($sessionPublicId));
    }

    /**
     * @param  array{name: string, code?: string|null, start_date: string, end_date: string}  $data
     * @return array<string, mixed>
     */
    public function createSession(UserIdentity $actor, string $schoolPublicId, array $data): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');
        $this->assertRange($data['start_date'], $data['end_date']);

        return DB::transaction(function () use ($actor, $data): array {
            $this->assertSessionOverlap($data['start_date'], $data['end_date']);
            $session = AcademicSession::query()->create([
                'name' => trim($data['name']),
                'code' => isset($data['code']) ? trim((string) $data['code']) : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => 'draft',
            ]);
            $this->audit($actor, 'academic.session_created', $session->public_id, ['status' => 'draft']);

            return $this->sessionData($session);
        });
    }

    /**
     * @param  array{name: string, code?: string|null, start_date: string, end_date: string}  $data
     * @return array<string, mixed>
     */
    public function updateSession(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId, array $data): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');
        $this->assertRange($data['start_date'], $data['end_date']);

        return DB::transaction(function () use ($actor, $sessionPublicId, $data): array {
            $session = $this->findSession($sessionPublicId, true);
            if ($session->status === 'closed') {
                throw $this->invalidPeriod('A closed academic session cannot be changed.');
            }
            $this->assertSessionOverlap($data['start_date'], $data['end_date'], $session->getKey());
            $session->fill([
                'name' => trim($data['name']),
                'code' => isset($data['code']) ? trim((string) $data['code']) : null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);
            $this->assertTermsWithinSession($session);
            $session->save();
            $this->audit($actor, 'academic.session_updated', $session->public_id, ['status' => $session->status]);

            return $this->sessionData($session);
        });
    }

    /** @return array<string, mixed> */
    public function transitionSession(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId, string $target, string $reason): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');

        return DB::transaction(function () use ($actor, $sessionPublicId, $target, $reason): array {
            $session = $this->findSession($sessionPublicId, true);
            $from = (string) $session->status;
            if ($from === $target && in_array($target, ['active', 'closed'], true)) {
                return $this->sessionData($session);
            }
            if (($target === 'active' && $from !== 'draft') || ($target === 'closed' && $from !== 'active')) {
                throw $this->invalidPeriod('The academic session transition is invalid.');
            }
            if ($target === 'active' && AcademicSession::query()->where('status', 'active')->whereKeyNot($session->getKey())->exists()) {
                throw $this->invalidPeriod('Another academic session is already active.');
            }
            if ($target === 'closed' && $session->terms()->where('status', 'active')->exists()) {
                throw $this->invalidPeriod('An active academic term must be closed first.');
            }
            $session->status = $target;
            $session->setAttribute($target === 'active' ? 'activated_at' : 'closed_at', now());
            $session->save();
            $this->audit($actor, 'academic.session_'.$target, $session->public_id, ['from' => $from, 'to' => $target, 'reason' => trim($reason)]);

            return $this->sessionData($session);
        });
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function terms(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.read');
        $session = $this->findSession($sessionPublicId);

        return ['items' => $session->terms()->orderBy('sequence')->get()->map(fn (AcademicTerm $term): array => $this->termData($term))->all()];
    }

    /**
     * @param  array{name: string, sequence: int, start_date: string, end_date: string}  $data
     * @return array<string, mixed>
     */
    public function createTerm(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId, array $data): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');
        $this->assertRange($data['start_date'], $data['end_date']);

        return DB::transaction(function () use ($actor, $sessionPublicId, $data): array {
            $session = $this->findSession($sessionPublicId, true);
            $this->assertWithinSession($session, $data['start_date'], $data['end_date']);
            $this->assertTermOverlap($session, $data['start_date'], $data['end_date']);
            $term = $session->terms()->create([
                'name' => trim($data['name']),
                'sequence' => $data['sequence'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => 'draft',
            ]);
            $this->audit($actor, 'academic.term_created', $term->public_id, ['session_id' => $session->public_id, 'status' => 'draft']);

            return $this->termData($term);
        });
    }

    /**
     * @param  array{name: string, sequence: int, start_date: string, end_date: string}  $data
     * @return array<string, mixed>
     */
    public function updateTerm(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId, string $termPublicId, array $data): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');
        $this->assertRange($data['start_date'], $data['end_date']);

        return DB::transaction(function () use ($actor, $sessionPublicId, $termPublicId, $data): array {
            $session = $this->findSession($sessionPublicId, true);
            $term = $this->findTerm($termPublicId, true);
            if ((int) $term->academic_session_id !== (int) $session->getKey()) {
                throw new ModelNotFoundException;
            }
            if ($term->status === 'closed') {
                throw $this->invalidPeriod('A closed academic term cannot be changed.');
            }
            $this->assertWithinSession($session, $data['start_date'], $data['end_date']);
            $this->assertTermOverlap($session, $data['start_date'], $data['end_date'], $term->getKey());
            $term->fill([
                'name' => trim($data['name']),
                'sequence' => $data['sequence'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
            ]);
            $term->save();
            $this->audit($actor, 'academic.term_updated', $term->public_id, ['session_id' => $session->public_id, 'status' => $term->status]);

            return $this->termData($term);
        });
    }

    /** @return array<string, mixed> */
    public function transitionTerm(UserIdentity $actor, string $schoolPublicId, string $sessionPublicId, string $termPublicId, string $target, string $reason): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.manage');

        return DB::transaction(function () use ($actor, $sessionPublicId, $termPublicId, $target, $reason): array {
            $session = $this->findSession($sessionPublicId, true);
            $term = $this->findTerm($termPublicId, true);
            if ((int) $term->academic_session_id !== (int) $session->getKey()) {
                throw new ModelNotFoundException;
            }
            $from = (string) $term->status;
            if ($from === $target && in_array($target, ['active', 'closed'], true)) {
                return $this->termData($term);
            }
            if (($target === 'active' && $from !== 'draft') || ($target === 'closed' && $from !== 'active')) {
                throw $this->invalidPeriod('The academic term transition is invalid.');
            }
            if ($target === 'active') {
                if ($session->status !== 'active' || AcademicTerm::query()->where('academic_session_id', $session->getKey())->where('status', 'active')->whereKeyNot($term->getKey())->exists()) {
                    throw $this->invalidPeriod('An active term requires an active session and no other active term.');
                }
            }
            $term->status = $target;
            $term->setAttribute($target === 'active' ? 'activated_at' : 'closed_at', now());
            $term->save();
            $this->audit($actor, 'academic.term_'.$target, $term->public_id, ['session_id' => $session->public_id, 'from' => $from, 'to' => $target, 'reason' => trim($reason)]);

            return $this->termData($term);
        });
    }

    /** @return array{session: array<string, mixed>|null, term: array<string, mixed>|null} */
    public function context(UserIdentity $actor, string $schoolPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'academic.sessions.read');
        $session = AcademicSession::query()->where('status', 'active')->first();
        $term = $session instanceof AcademicSession
            ? $session->terms()->where('status', 'active')->first()
            : null;

        return [
            'session' => $session instanceof AcademicSession ? $this->sessionData($session) : null,
            'term' => $term instanceof AcademicTerm ? $this->termData($term) : null,
        ];
    }

    private function authorize(UserIdentity $actor, string $schoolPublicId, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $schoolPublicId || ! $this->permissions->allows($actor, $schoolPublicId, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function findSession(string $publicId, bool $lock = false): AcademicSession
    {
        $query = AcademicSession::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $session = $query->first();
        if (! $session instanceof AcademicSession) {
            throw new ModelNotFoundException;
        }

        return $session;
    }

    private function findTerm(string $publicId, bool $lock = false): AcademicTerm
    {
        $query = AcademicTerm::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $term = $query->first();
        if (! $term instanceof AcademicTerm) {
            throw new ModelNotFoundException;
        }

        return $term;
    }

    private function assertRange(string $start, string $end): void
    {
        if ($start > $end) {
            throw $this->invalidPeriod('The end date must be on or after the start date.');
        }
    }

    private function assertSessionOverlap(string $start, string $end, ?int $exceptId = null): void
    {
        $query = AcademicSession::query()
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidPeriod('Academic session dates overlap an existing session.');
        }
    }

    private function assertTermOverlap(AcademicSession $session, string $start, string $end, ?int $exceptId = null): void
    {
        $query = $session->terms()->where('start_date', '<=', $end)->where('end_date', '>=', $start);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw $this->invalidPeriod('Academic term dates overlap an existing term.');
        }
    }

    private function assertWithinSession(AcademicSession $session, string $start, string $end): void
    {
        if ($start < $this->dateString($session->getAttribute('start_date')) || $end > $this->dateString($session->getAttribute('end_date'))) {
            throw $this->invalidPeriod('Academic term dates must remain within the session dates.');
        }
    }

    private function assertTermsWithinSession(AcademicSession $session): void
    {
        if ($session->terms()->where(function ($query) use ($session): void {
            $query->where('start_date', '<', $this->dateString($session->getAttribute('start_date')))
                ->orWhere('end_date', '>', $this->dateString($session->getAttribute('end_date')));
        })->exists()) {
            throw $this->invalidPeriod('The session dates cannot exclude an existing term.');
        }
    }

    private function invalidPeriod(string $message): ValidationException
    {
        return ValidationException::withMessages(['academic_period' => [$message]]);
    }

    /** @param array<string, mixed> $transition */
    private function audit(UserIdentity $actor, string $action, string $subjectPublicId, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(
            action: $action,
            subjectType: 'academic_period',
            subjectPublicId: $subjectPublicId,
            schoolId: $context->schoolId,
            actorId: (int) $actor->getAuthIdentifier(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'academic.sessions.manage'],
            stateTransition: $transition,
            requiresSchoolContext: true,
        ));
    }

    /** @return array<string, mixed> */
    private function sessionData(AcademicSession $session): array
    {
        $code = $session->getAttribute('code');

        return [
            'id' => (string) $session->public_id,
            'name' => (string) $session->name,
            'code' => is_string($code) ? $code : null,
            'start_date' => $this->dateString($session->getAttribute('start_date')),
            'end_date' => $this->dateString($session->getAttribute('end_date')),
            'status' => (string) $session->status,
            'activated_at' => $this->timestamp($session->getAttribute('activated_at')),
            'closed_at' => $this->timestamp($session->getAttribute('closed_at')),
        ];
    }

    /** @return array<string, mixed> */
    private function termData(AcademicTerm $term): array
    {
        return [
            'id' => (string) $term->public_id,
            'name' => (string) $term->name,
            'sequence' => (int) $term->sequence,
            'start_date' => $this->dateString($term->getAttribute('start_date')),
            'end_date' => $this->dateString($term->getAttribute('end_date')),
            'status' => (string) $term->status,
            'activated_at' => $this->timestamp($term->getAttribute('activated_at')),
            'closed_at' => $this->timestamp($term->getAttribute('closed_at')),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }

    private function dateString(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : substr((string) $value, 0, 10);
    }
}
