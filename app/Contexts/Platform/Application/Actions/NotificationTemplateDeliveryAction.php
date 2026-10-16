<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Application\Actions;

use App\Contexts\Platform\Application\Contracts\NotificationDelivery;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Contexts\Platform\Domain\Models\NotificationDelivery as NotificationDeliveryModel;
use App\Contexts\Platform\Domain\Models\NotificationTemplate;
use App\Support\Authorization\SchoolPermissionChecker;
use App\Support\Http\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationTemplateDeliveryAction
{
    public function __construct(
        private readonly SchoolPermissionChecker $permissions,
        private readonly RecordAuditEventAction $audit,
        private readonly NotificationDelivery $delivery,
    ) {}

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    public function templates(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.read');
        $context = TenantContext::require();
        $items = NotificationTemplate::query()->where('school_id', $context->schoolId)->orderBy('template_key')->orderBy('channel')->orderByDesc('version')->get()->map(fn (NotificationTemplate $template): array => $this->templateData($template))->all();
        $this->readAudit($actor, $context->schoolId, 'notification.templates_read', count($items));

        return ['templates' => $items];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{template: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function createTemplate(Authenticatable $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.manage');
        $context = TenantContext::require();
        $template = DB::transaction(function () use ($actor, $context, $input): NotificationTemplate {
            $version = ((int) NotificationTemplate::query()->where('school_id', $context->schoolId)->where('template_key', $input['template_key'])->where('channel', $input['channel'])->max('version')) + 1;

            return NotificationTemplate::query()->create([...$input, 'school_id' => $context->schoolId, 'version' => $version, 'status' => 'draft', 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id')]);
        });
        $this->record($actor, 'notification.template_created', $template, ['to' => 'draft']);

        return ['template' => $this->templateData($template)];
    }

    /** @return array{template: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function showTemplate(Authenticatable $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.read');

        return ['template' => $this->templateData($this->template($id))];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{template: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function updateTemplate(Authenticatable $actor, string $school, string $id, array $input): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.manage');
        $template = $this->template($id, true);
        if ($template->status !== 'draft') {
            throw new ModelNotFoundException;
        }
        $template->update([...$input, 'updated_by' => $actor->getKey()]);
        $this->record($actor, 'notification.template_updated', $template, ['to' => 'draft']);

        return ['template' => $this->templateData($template->fresh())];
    }

    /** @return array{template: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function lifecycle(Authenticatable $actor, string $school, string $id, string $transition): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.manage');
        $context = TenantContext::require();

        $template = DB::transaction(function () use ($actor, $context, $id, $transition): NotificationTemplate {
            $template = $this->template($id, true);
            $from = (string) $template->status;
            $to = $transition === 'activate' ? 'active' : 'retired';
            if ($from === $to) {
                return $template;
            }
            if (($transition === 'activate' && $from !== 'draft') || ($transition === 'retire' && $from !== 'active')) {
                throw new ModelNotFoundException;
            }
            if ($to === 'active') {
                NotificationTemplate::query()->where('school_id', $context->schoolId)->where('template_key', $template->template_key)->where('channel', $template->channel)->where('status', 'active')->lockForUpdate()->update(['status' => 'retired', 'retired_at' => now(), 'updated_by' => $actor->getKey()]);
                $template->update(['status' => 'active', 'activated_at' => now(), 'updated_by' => $actor->getKey()]);
            } else {
                $template->update(['status' => 'retired', 'retired_at' => now(), 'updated_by' => $actor->getKey()]);
            }
            $this->record($actor, 'notification.template_'.$transition.'d', $template, ['from' => $from, 'to' => $to]);

            return $template->fresh();
        });

        return ['template' => $this->templateData($template)];
    }

    /** @return array{preview: array<string, string>} */
    public function preview(Authenticatable $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'operations.notification-templates.read');
        $template = $this->template($id);

        return ['preview' => ['title' => str_replace(['{{recipient_name}}', '{{school_name}}'], ['Recipient', 'School'], $template->title), 'body' => str_replace(['{{recipient_name}}', '{{school_name}}'], ['Recipient', 'School'], $template->body)]];
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    public function deliveries(Authenticatable $actor, string $school): array
    {
        $this->authorize($actor, $school, 'operations.notifications.read');
        $context = TenantContext::require();
        $items = NotificationDeliveryModel::query()->where('school_id', $context->schoolId)->with('template')->orderByDesc('id')->limit(ApiResponse::perPage(request()->integer('per_page')))->get()->map(fn (NotificationDeliveryModel $item): array => $this->deliveryData($item))->all();
        $this->readAudit($actor, $context->schoolId, 'notification.deliveries_read', count($items));

        return ['notifications' => $items];
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $input
     * @return array{notification: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>}
     */
    public function createDelivery(Authenticatable $actor, string $school, array $input): array
    {
        $this->authorize($actor, $school, 'operations.notifications.manage');
        $context = TenantContext::require();
        $delivery = DB::transaction(function () use ($actor, $context, $input): NotificationDeliveryModel {
            $template = $this->template((string) $input['template_id'], true);
            if ($template->status !== 'active' || $template->channel !== $input['channel']) {
                throw new ModelNotFoundException;
            }
            $recipient = $this->recipient($context->schoolId, (string) $input['recipient_type'], (string) $input['recipient_id']);
            $idempotency = $this->hash($context->schoolId, implode('|', [$input['idempotency_key'], $template->id, $input['recipient_id'], $input['source_type'], $input['source_id'] ?? '']));
            $existing = NotificationDeliveryModel::query()->where('school_id', $context->schoolId)->where('idempotency_hash', $idempotency)->lockForUpdate()->first();
            if ($existing instanceof NotificationDeliveryModel) {
                return $existing->load('template');
            }

            return NotificationDeliveryModel::query()->create(['school_id' => $context->schoolId, 'notification_template_id' => $template->id, 'channel' => $template->channel, 'recipient_public_id' => $recipient, 'recipient_reference_hash' => $this->hash($context->schoolId, 'recipient|'.$recipient), 'recipient_type' => $input['recipient_type'], 'source_type' => $input['source_type'], 'source_public_id' => $input['source_id'] ?? null, 'idempotency_hash' => $idempotency, 'status' => 'queued', 'created_by' => $actor->getKey(), 'request_id' => Context::get('request_id'), 'queued_at' => now()])->load('template');
        });
        $this->record($actor, 'notification.created', $delivery, ['to' => 'queued']);

        return ['notification' => $this->deliveryData($delivery)];
    }

    /** @return array{notification: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function showDelivery(Authenticatable $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'operations.notifications.read');

        return ['notification' => $this->deliveryData($this->deliveryRecord($id))];
    }

    /** @return array{notification: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function send(Authenticatable $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'operations.notifications.send');

        return DB::transaction(function () use ($actor, $id): array {
            $delivery = $this->deliveryRecord($id, true)->load('template');
            if ($delivery->status === 'sent') {
                return ['notification' => $this->deliveryData($delivery)];
            }
            if ($delivery->status !== 'queued' || $delivery->template?->status !== 'active') {
                throw new ModelNotFoundException;
            }
            $result = $this->delivery->send((string) $delivery->channel, (string) $delivery->recipient_reference_hash, (string) $delivery->public_id);
            $delivery->update($result['accepted'] ? ['status' => 'sent', 'sent_at' => now()] : ['status' => 'failed', 'failed_at' => now(), 'failure_code' => $result['failure_code']]);
            $this->record($actor, $result['accepted'] ? 'notification.sent' : 'notification.failed', $delivery, ['from' => 'queued', 'to' => $result['accepted'] ? 'sent' : 'failed']);

            return ['notification' => $this->deliveryData($delivery->fresh()->load('template'))];
        });
    }

    /** @return array{notification: array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>>} */
    public function cancel(Authenticatable $actor, string $school, string $id): array
    {
        $this->authorize($actor, $school, 'operations.notifications.manage');

        return DB::transaction(function () use ($actor, $id): array {
            $delivery = $this->deliveryRecord($id, true)->load('template');
            if ($delivery->status === 'cancelled') {
                return ['notification' => $this->deliveryData($delivery)];
            }
            if (! in_array($delivery->status, ['queued', 'failed'], true)) {
                throw new ModelNotFoundException;
            }
            $delivery->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->record($actor, 'notification.cancelled', $delivery, ['from' => 'queued', 'to' => 'cancelled']);

            return ['notification' => $this->deliveryData($delivery->fresh()->load('template'))];
        });
    }

    private function template(string $id, bool $lock = false): NotificationTemplate
    {
        $query = NotificationTemplate::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function deliveryRecord(string $id, bool $lock = false): NotificationDeliveryModel
    {
        $query = NotificationDeliveryModel::query()->where('public_id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function recipient(int $schoolId, string $type, string $id): string
    {
        if ($type === 'guardian' && ('App\\Contexts\\Registry\\Domain\\Models\\StudentGuardianRelationship')::query()->where('school_id', $schoolId)->where('status', 'active')->whereNotNull('verified_at')->whereHas('guardianProfile.identity', fn ($query) => $query->where('public_id', $id))->whereHas('guardianProfile.identity.contacts', fn ($query) => $query->whereNotNull('verified_at')->whereNull('superseded_at'))->exists()) {
            return $id;
        }
        if ($type === 'staff' && ('App\\Contexts\\Identity\\Domain\\Models\\SchoolMembership')::query()->where('school_id', $schoolId)->where('status', 'active')->whereHas('identity', fn ($query) => $query->where('public_id', $id))->exists()) {
            return $id;
        }
        throw new ModelNotFoundException;
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function templateData(NotificationTemplate $template): array
    {
        return ['id' => (string) $template->public_id, 'template_key' => (string) $template->template_key, 'channel' => (string) $template->channel, 'version' => (int) $template->version, 'title' => (string) $template->title, 'body' => (string) $template->body, 'status' => (string) $template->status, 'activated_at' => $this->atom($template->activated_at), 'retired_at' => $this->atom($template->retired_at)];
    }

    /** @return array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> */
    private function deliveryData(NotificationDeliveryModel $delivery): array
    {
        return ['id' => (string) $delivery->public_id, 'template_id' => (string) $delivery->template?->public_id, 'channel' => (string) $delivery->channel, 'recipient_type' => (string) $delivery->recipient_type, 'recipient_id' => (string) $delivery->recipient_public_id, 'source_type' => (string) $delivery->source_type, 'source_id' => $delivery->source_public_id, 'status' => (string) $delivery->status, 'failure_code' => $delivery->failure_code, 'queued_at' => $this->atom($delivery->queued_at), 'sent_at' => $this->atom($delivery->sent_at), 'failed_at' => $this->atom($delivery->failed_at), 'cancelled_at' => $this->atom($delivery->cancelled_at)];
    }

    private function authorize(Authenticatable $actor, string $school, string $permission): void
    {
        $context = TenantContext::require();
        if ($context->schoolPublicId !== $school || ! $this->permissions->allows($actor, $school, $permission)) {
            throw new ModelNotFoundException;
        }
    }

    private function hash(int $schoolId, string $value): string
    {
        return hash_hmac('sha256', 'notification|'.$schoolId.'|'.$value, (string) config('app.key'));
    }

    private function readAudit(Authenticatable $actor, int $schoolId, string $action, int $count): void
    {
        $this->audit->execute(new AuditEventData(action: $action, subjectType: 'notification_projection', subjectPublicId: null, schoolId: $schoolId, actorId: $actor->getKey(), requestId: Context::get('request_id'), metadata: ['result_count' => $count], requiresSchoolContext: true));
    }

    /** @param array<string, string|int|float|bool|null|array<string, string|int|float|bool|null>|list<string|int|float|bool|null>|list<array<string, string|int|float|bool|null>>> $transition */
    private function record(Authenticatable $actor, string $action, object $subject, array $transition): void
    {
        $context = TenantContext::require();
        $this->audit->execute(new AuditEventData(action: $action, subjectType: Str::snake(class_basename($subject)), subjectPublicId: (string) $subject->public_id, schoolId: $context->schoolId, actorId: $actor->getKey(), requestId: Context::get('request_id'), stateTransition: $transition, metadata: [], requiresSchoolContext: true));
    }

    private function atom(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toAtomString() : ($value === null ? null : (string) $value);
    }
}
