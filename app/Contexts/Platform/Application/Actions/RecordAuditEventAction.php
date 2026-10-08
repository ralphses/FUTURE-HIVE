<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\AuditEvent;
use App\Support\Observability\SensitiveDataRedactor;
use Illuminate\Support\Facades\Context;

final class RecordAuditEventAction
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function execute(AuditEventData $data): AuditEvent
    {
        if ($data->requiresSchoolContext && $data->schoolId === null) {
            throw new \InvalidArgumentException('A school_id is required for school-owned audit events.');
        }

        return AuditEvent::query()->create([
            'school_id' => $data->schoolId,
            'actor_id' => $data->actorId,
            'action' => $data->action,
            'subject_type' => $data->subjectType,
            'subject_public_id' => $data->subjectPublicId,
            'reason' => $data->reason,
            'request_id' => $data->requestId ?? Context::get('request_id'),
            'authorization_context' => $this->redactor->redact($data->authorizationContext),
            'state_transition' => $this->redactor->redact($data->stateTransition),
            'metadata' => $this->redactor->redact($data->metadata),
        ]);
    }
}
