<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Application\Services\ImportTemplateCatalog;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;

final class ImportTemplateAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly ImportTemplateCatalog $catalog,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor, $school);

        return array_values(array_map(function (array $template): array {
            return [
                'key' => $template['key'],
                'name' => $template['name'],
                'version' => $template['version'],
                'formats' => $template['formats'],
                'fields' => $template['fields'],
            ];
        }, $this->catalog->all()));
    }

    /** @return array<string, mixed> */
    public function download(Authenticatable $actor, string $school, string $key, string $format): array
    {
        $this->authorize($actor, $school);
        $template = $this->catalog->get($key);
        if (! in_array($format, $template['formats'], true)) {
            throw new ModelNotFoundException;
        }

        $this->audit->execute(new AuditEventData(
            action: 'import_template.downloaded',
            subjectType: 'import_template',
            subjectPublicId: $template['key'].'-'.$template['version'],
            schoolId: TenantContext::require()->schoolId,
            actorId: $actor->getKey(),
            requestId: Context::get('request_id'),
            authorizationContext: ['permission' => 'imports.templates.read'],
            metadata: ['template' => $template['key'], 'version' => $template['version'], 'format' => $format],
            requiresSchoolContext: true,
        ));

        return $template;
    }

    private function authorize(Authenticatable $actor, string $school): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, 'imports.templates.read')) {
            throw new ModelNotFoundException;
        }
    }
}
