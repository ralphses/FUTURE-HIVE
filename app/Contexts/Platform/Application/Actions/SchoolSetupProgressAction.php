<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\SchoolSetupChecklistItem;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchoolSetupProgressAction
{
    /** @var list<string> */
    public const ITEM_KEYS = [
        'school_details',
        'owner_access',
        'school_settings',
        'staff_setup',
    ];

    /** @var list<string> */
    public const STATUSES = ['pending', 'completed'];

    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return array{school_id: string, items: list<array{key: string, status: string, completed_at: string|null}>, completed_count: int, total_count: int, completion_percentage: int} */
    public function list(Authenticatable $actor, string $schoolPublicId): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.read');

        $items = SchoolSetupChecklistItem::query()->orderBy('item_key')->get();
        $this->assertCompleteChecklist($items->pluck('item_key')->all());
        $this->assertValidStatuses($items->pluck('status')->all());

        $completedCount = $items->where('status', 'completed')->count();

        return [
            'school_id' => $schoolPublicId,
            'items' => $items->map(fn (SchoolSetupChecklistItem $item): array => [
                'key' => (string) $item->item_key,
                'status' => (string) $item->status,
                'completed_at' => $this->completedAt($item),
            ])->values()->all(),
            'completed_count' => $completedCount,
            'total_count' => count(self::ITEM_KEYS),
            'completion_percentage' => (int) (($completedCount / count(self::ITEM_KEYS)) * 100),
        ];
    }

    /** @return array{key: string, status: string, completed_at: string|null} */
    public function update(Authenticatable $actor, string $schoolPublicId, string $itemKey, string $status): array
    {
        $this->authorize($actor, $schoolPublicId, 'school.settings.manage');

        if (! in_array($itemKey, self::ITEM_KEYS, true) || ! in_array($status, self::STATUSES, true)) {
            throw (new ModelNotFoundException)->setModel(SchoolSetupChecklistItem::class);
        }

        return DB::transaction(function () use ($actor, $itemKey, $status): array {
            $item = SchoolSetupChecklistItem::query()->where('item_key', $itemKey)->lockForUpdate()->first();

            if (! $item instanceof SchoolSetupChecklistItem) {
                throw new RuntimeException('The school setup checklist is incomplete.');
            }

            $previousStatus = (string) $item->status;
            $item->status = $status;
            $item->setAttribute('completed_at', $status === 'completed' ? now() : null);
            $item->save();

            if ($previousStatus !== $status) {
                $this->audit->execute(new AuditEventData(
                    action: 'school.setup_progress_updated',
                    subjectType: SchoolSetupChecklistItem::class,
                    subjectPublicId: null,
                    schoolId: TenantContext::require()->schoolId,
                    actorId: (int) $actor->getAuthIdentifier(),
                    requestId: Context::get('request_id'),
                    authorizationContext: ['permission' => 'school.settings.manage'],
                    stateTransition: ['item' => $itemKey, 'from' => $previousStatus, 'to' => $status],
                    requiresSchoolContext: true,
                ));
            }

            return [
                'key' => (string) $item->item_key,
                'status' => (string) $item->status,
                'completed_at' => $this->completedAt($item),
            ];
        });
    }

    private function authorize(Authenticatable $actor, string $schoolPublicId, string $permission): void
    {
        $context = TenantContext::require();

        if ($context->schoolPublicId !== $schoolPublicId || ! $this->permissions->allows($actor, $schoolPublicId, $permission)) {
            throw (new ModelNotFoundException)->setModel(SchoolSetupChecklistItem::class);
        }
    }

    /** @param list<string> $keys */
    private function assertCompleteChecklist(array $keys): void
    {
        sort($keys);
        $expected = self::ITEM_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            throw new RuntimeException('The school setup checklist is incomplete.');
        }
    }

    /** @param list<mixed> $statuses */
    private function assertValidStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            if (! in_array((string) $status, self::STATUSES, true)) {
                throw new RuntimeException('The school setup checklist contains an invalid state.');
            }
        }
    }

    private function completedAt(SchoolSetupChecklistItem $item): ?string
    {
        $completedAt = $item->getAttribute('completed_at');

        return $completedAt instanceof CarbonInterface ? $completedAt->toISOString() : null;
    }
}
