<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\PlatformPermissionResolver;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\PlatformFeatureFlag;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

final class PlatformFeatureFlagAction
{
    /** @var array<string, array{type: string, values?: list<string>}> */
    private const ALLOWED_FLAGS = [
        'academic.results' => ['type' => 'boolean'],
        'communications.announcements' => ['type' => 'boolean'],
        'finance.reminders' => ['type' => 'boolean'],
        'platform.support-access' => ['type' => 'boolean'],
        'portal.parent-dashboard' => ['type' => 'enum', 'values' => ['enabled', 'limited', 'disabled']],
    ];

    public function __construct(
        private readonly PlatformPermissionResolver $permissions,
        private readonly RecordAuditEventAction $audit,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>> */
    public function index(Authenticatable $actor, int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor, 'platform.feature-flags.read');
        $paginator = PlatformFeatureFlag::query()->where('scope', 'platform')->orderBy('flag_key')->orderByDesc('version')->paginate($perPage, ['*'], 'page', $page);

        return $paginator->through(fn (PlatformFeatureFlag $flag): array => $this->data($flag));
    }

    /** @return array{flag: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function show(Authenticatable $actor, string $flagId): array
    {
        $this->authorize($actor, 'platform.feature-flags.read');

        return ['flag' => $this->data($this->flag($flagId))];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{flag: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function create(Authenticatable $actor, array $input): array
    {
        $this->authorize($actor, 'platform.feature-flags.manage');
        $this->validateValue((string) $input['flag_key'], $input['value']);
        $flag = DB::transaction(function () use ($actor, $input): PlatformFeatureFlag {
            $key = (string) $input['flag_key'];
            $version = ((int) PlatformFeatureFlag::query()->where('flag_key', $key)->where('scope', 'platform')->lockForUpdate()->max('version')) + 1;
            $flag = PlatformFeatureFlag::query()->create([
                'flag_key' => $key,
                'label' => $input['label'],
                'description' => $input['description'] ?? null,
                'value_type' => self::ALLOWED_FLAGS[$key]['type'],
                'value' => $input['value'],
                'scope' => 'platform',
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.feature_flag.created', $flag);

            return $flag;
        });

        return ['flag' => $this->data($flag)];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{flag: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function update(Authenticatable $actor, string $flagId, array $input): array
    {
        $this->authorize($actor, 'platform.feature-flags.manage');
        $flag = DB::transaction(function () use ($actor, $flagId, $input): PlatformFeatureFlag {
            $flag = $this->flag($flagId, true);
            if ($flag->status !== 'draft' || $flag->scope !== 'platform') {
                throw new ModelNotFoundException;
            }
            $value = $input['value'] ?? $flag->value;
            $this->validateValue($flag->flag_key, $value);
            $flag->update([
                'label' => $input['label'] ?? $flag->label,
                'description' => array_key_exists('description', $input) ? $input['description'] : $flag->description,
                'value' => $value,
            ]);
            $this->audit($actor, 'platform.feature_flag.updated', $flag);

            return $flag;
        });

        return ['flag' => $this->data($flag)];
    }

    /** @return array{flag: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function lifecycle(Authenticatable $actor, string $flagId, string $transition): array
    {
        $this->authorize($actor, 'platform.feature-flags.manage');
        $flag = DB::transaction(function () use ($actor, $flagId, $transition): PlatformFeatureFlag {
            $flag = $this->flag($flagId, true);
            $target = match ($transition) {
                'activate' => 'active',
                'retire' => 'retired',
                default => throw new ModelNotFoundException,
            };
            if ($flag->status === $target) {
                return $flag;
            }
            if (! (($flag->status === 'draft' && $target === 'active') || ($flag->status === 'active' && $target === 'retired'))) {
                throw new ModelNotFoundException;
            }
            if ($target === 'active' && PlatformFeatureFlag::query()->where('flag_key', $flag->flag_key)->where('scope', $flag->scope)->where('school_id', $flag->school_id)->where('status', 'active')->exists()) {
                throw new ModelNotFoundException;
            }
            $from = $flag->status;
            $flag->update([
                'status' => $target,
                'activated_by' => $target === 'active' ? $actor->getAuthIdentifier() : $flag->activated_by,
                'activated_at' => $target === 'active' ? now() : $flag->activated_at,
                'retired_by' => $target === 'retired' ? $actor->getAuthIdentifier() : $flag->retired_by,
                'retired_at' => $target === 'retired' ? now() : $flag->retired_at,
            ]);
            $this->audit($actor, 'platform.feature_flag.'.$target, $flag, ['from' => $from, 'to' => $target]);

            return $flag;
        });

        return ['flag' => $this->data($flag)];
    }

    /** @return array{flags: list<array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>>} */
    public function schoolFlags(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor, 'platform.feature-flags.read');
        $schoolModel = DB::table('schools')->where('public_id', $school)->where('status', 'active')->firstOrFail();
        $platform = PlatformFeatureFlag::query()->where('scope', 'platform')->where('status', 'active')->get()->keyBy('flag_key');
        $overrides = PlatformFeatureFlag::query()->where('scope', 'school')->where('school_id', $schoolModel->id)->where('status', 'active')->get()->keyBy('flag_key');
        $keys = array_keys(self::ALLOWED_FLAGS);

        return ['flags' => array_map(function (string $key) use ($platform, $overrides): array {
            $flag = $overrides->get($key) ?? $platform->get($key);

            return [
                'key' => $key,
                'value' => $flag === null ? false : $flag->value,
                'status' => $flag === null ? 'disabled' : $flag->status,
                'source' => $overrides->has($key) ? 'school_override' : ($platform->has($key) ? 'platform_default' : 'none'),
                'version' => $flag?->version,
            ];
        }, $keys)];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{flag: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function putSchoolOverride(Authenticatable $actor, string $school, string $flagId, array $input): array
    {
        $this->authorize($actor, 'platform.feature-flags.manage');
        $schoolModel = DB::table('schools')->where('public_id', $school)->where('status', 'active')->firstOrFail();
        $base = $this->flag($flagId);
        if ($base->scope !== 'platform') {
            throw new ModelNotFoundException;
        }
        $this->validateValue($base->flag_key, $input['value']);
        $flag = DB::transaction(function () use ($actor, $schoolModel, $base, $input): PlatformFeatureFlag {
            $version = ((int) PlatformFeatureFlag::query()->where('flag_key', $base->flag_key)->where('scope', 'school')->where('school_id', $schoolModel->id)->lockForUpdate()->max('version')) + 1;
            $flag = PlatformFeatureFlag::query()->create([
                'flag_key' => $base->flag_key,
                'label' => $base->label,
                'description' => $base->description,
                'value_type' => $base->value_type,
                'value' => $input['value'],
                'scope' => 'school',
                'school_id' => $schoolModel->id,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $actor->getAuthIdentifier(),
                'request_id' => Context::get('request_id'),
            ]);
            $this->audit($actor, 'platform.feature_flag.school_override_created', $flag, [], (int) $schoolModel->id);

            return $flag;
        });

        return ['flag' => $this->data($flag)];
    }

    private function authorize(Authenticatable $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function flag(string $publicId, bool $lock = false): PlatformFeatureFlag
    {
        $query = PlatformFeatureFlag::query()->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function validateValue(string $key, mixed $value): void
    {
        $definition = self::ALLOWED_FLAGS[$key] ?? null;
        if ($definition === null || ($definition['type'] === 'boolean' && ! is_bool($value)) || ($definition['type'] === 'enum' && ! in_array($value, $definition['values'], true))) {
            throw new ModelNotFoundException;
        }
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $transition */
    private function audit(Authenticatable $actor, string $action, PlatformFeatureFlag $flag, array $transition = [], ?int $schoolId = null): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'platform_feature_flag', subjectPublicId: (string) $flag->public_id, schoolId: $schoolId, actorId: (int) $actor->getAuthIdentifier(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: ['scope' => $flag->scope, 'status' => $flag->status, 'version' => $flag->version]));
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function data(PlatformFeatureFlag $flag): array
    {
        return ['id' => (string) $flag->public_id, 'key' => $flag->flag_key, 'label' => $flag->label, 'description' => $flag->description, 'value_type' => $flag->value_type, 'value' => $flag->value, 'scope' => $flag->scope, 'version' => (int) $flag->version, 'status' => $flag->status, 'school' => $flag->school_id === null ? null : 'scoped', 'activated_at' => $flag->activated_at === null ? null : CarbonImmutable::parse((string) $flag->activated_at)->toISOString(), 'retired_at' => $flag->retired_at === null ? null : CarbonImmutable::parse((string) $flag->retired_at)->toISOString()];
    }
}
