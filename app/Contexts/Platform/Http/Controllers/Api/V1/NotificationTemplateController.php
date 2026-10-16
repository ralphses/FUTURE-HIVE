<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\NotificationTemplateDeliveryAction;
use App\Contexts\Platform\Http\Requests\StoreNotificationRequest;
use App\Contexts\Platform\Http\Requests\StoreNotificationTemplateRequest;
use App\Contexts\Platform\Http\Requests\UpdateNotificationTemplateRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationTemplateController
{
    #[Endpoint(title: 'List notification templates', description: 'Lists safe school-scoped notification template versions. Template content is bounded and provider credentials are never returned.')]
    public function templates(Request $request, string $school, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->templates($request->user(), $school));
    }

    #[Endpoint(title: 'Create notification template', description: 'Creates a draft, versioned notification template. The server controls school ownership and lifecycle.')]
    public function storeTemplate(StoreNotificationTemplateRequest $request, string $school, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->createTemplate($request->user(), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View notification template', description: 'Shows one safe notification template version in the trusted school.')]
    public function showTemplate(Request $request, string $school, string $template, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->showTemplate($request->user(), $school, $template));
    }

    #[Endpoint(title: 'Update notification template', description: 'Updates a draft notification template. Active and retired versions are immutable.')]
    public function updateTemplate(UpdateNotificationTemplateRequest $request, string $school, string $template, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->updateTemplate($request->user(), $school, $template, $request->validated()));
    }

    #[Endpoint(title: 'Activate notification template', description: 'Activates a draft template version and retires any prior active version for the same key and channel.')]
    public function activate(Request $request, string $school, string $template, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->lifecycle($request->user(), $school, $template, 'activate'));
    }

    #[Endpoint(title: 'Retire notification template', description: 'Retires an active notification template without deleting its history.')]
    public function retire(Request $request, string $school, string $template, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->lifecycle($request->user(), $school, $template, 'retire'));
    }

    #[Endpoint(title: 'Preview notification template', description: 'Renders a redacted preview using fixed sample values and never sends a message.')]
    public function preview(Request $request, string $school, string $template, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->preview($request->user(), $school, $template));
    }

    #[Endpoint(title: 'List notification deliveries', description: 'Lists safe notification delivery states without contacts, credentials or provider payloads.')]
    public function notifications(Request $request, string $school, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->deliveries($request->user(), $school));
    }

    #[Endpoint(title: 'Create notification delivery', description: 'Creates an idempotent queued notification for a server-authorized recipient and active template.')]
    public function storeNotification(StoreNotificationRequest $request, string $school, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->createDelivery($request->user(), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View notification delivery', description: 'Shows safe delivery state and public references only.')]
    public function showNotification(Request $request, string $school, string $notification, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->showDelivery($request->user(), $school, $notification));
    }

    #[Endpoint(title: 'Send notification delivery', description: 'Sends through the provider-neutral application contract. Local verification uses a deterministic fake and no network call.')]
    public function send(Request $request, string $school, string $notification, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->send($request->user(), $school, $notification));
    }

    #[Endpoint(title: 'Cancel notification delivery', description: 'Cancels an eligible queued or failed delivery without changing the recipient or source record.')]
    public function cancel(Request $request, string $school, string $notification, NotificationTemplateDeliveryAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($request->user(), $school, $notification));
    }
}
