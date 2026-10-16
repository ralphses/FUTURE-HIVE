<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Http\Controllers\Api\V1;

use App\Contexts\Platform\Application\Actions\PlatformSubscriptionLifecycleAction;
use App\Contexts\Platform\Application\Actions\PlatformSubscriptionPlanAction;
use App\Contexts\Platform\Http\Requests\RenewPlatformSubscriptionRequest;
use App\Contexts\Platform\Http\Requests\ReopenPlatformSubscriptionGraceRequest;
use App\Contexts\Platform\Http\Requests\StorePlatformSubscriptionPlanRequest;
use App\Contexts\Platform\Http\Requests\StorePlatformSubscriptionRequest;
use App\Contexts\Platform\Http\Requests\UpdatePlatformSubscriptionPlanRequest;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformSubscriptionPlanController
{
    #[Endpoint(title: 'List platform subscription plans', description: 'Lists safe school-authorized, school-independent configuration-only subscription plan versions. Pricing, billing and suspension rules are deferred.')]
    public function index(Request $request, string $school, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data(['plans' => $action->index($this->identity($request), $school)]);
    }

    #[Endpoint(title: 'Create a platform subscription plan', description: 'Creates a draft platform plan with bounded entitlement keys. No school membership, billing or payment state is changed.')]
    public function store(StorePlatformSubscriptionPlanRequest $request, string $school, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'View a platform subscription plan', description: 'Returns a safe immutable-version projection without pricing, credentials or internal identifiers.')]
    public function show(Request $request, string $school, string $plan, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->show($this->identity($request), $school, $plan));
    }

    #[Endpoint(title: 'Update a draft platform subscription plan', description: 'Updates draft metadata only; active and retired versions are immutable.')]
    public function update(UpdatePlatformSubscriptionPlanRequest $request, string $school, string $plan, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->update($this->identity($request), $school, $plan, $request->validated()));
    }

    #[Endpoint(title: 'Change platform subscription plan lifecycle', description: 'Activates or retires a plan version with one-active-code protection and no billing side effects.')]
    public function lifecycle(Request $request, string $school, string $plan, string $transition, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        abort_unless(in_array($transition, ['activate', 'retire'], true), 404);

        return ApiResponse::data($action->lifecycle($this->identity($request), $school, $plan, $transition));
    }

    #[Endpoint(title: 'View effective school entitlements', description: 'Returns the active plan entitlement projection for the trusted school, or an unassigned status. It never grants permissions or changes membership.')]
    public function entitlement(Request $request, string $school, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->entitlement($this->identity($request), $school));
    }

    #[Endpoint(title: 'Assign an active platform plan to a school', description: 'Assigns one active plan to the trusted school and retains the previous assignment as revoked. This is configuration only and does not change authorization claims.')]
    public function assign(Request $request, string $school, string $plan, PlatformSubscriptionPlanAction $action): JsonResponse
    {
        return ApiResponse::data($action->assign($this->identity($request), $school, $plan));
    }

    #[Endpoint(title: 'View the effective school subscription', description: 'Returns the trusted school subscription lifecycle and effective entitlement state. Expiry and grace status are evaluated from server time without pricing or payment data.')]
    public function subscription(Request $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->current($this->identity($request), $school));
    }

    #[Endpoint(title: 'Start a school subscription lifecycle', description: 'Starts a trial or active lifecycle from the trusted school plan assignment. Dates and status are server-controlled; no billing or payment state is created.')]
    public function createSubscription(StorePlatformSubscriptionRequest $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->create($this->identity($request), $school, $request->validated()), 201);
    }

    #[Endpoint(title: 'List school subscription history', description: 'Lists historical subscription lifecycle records with safe public references and effective server-time status.')]
    public function subscriptionHistory(Request $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->history($this->identity($request), $school));
    }

    #[Endpoint(title: 'Renew a school subscription', description: 'Closes the current lifecycle as renewed and creates a new historical lifecycle from the active assigned plan. No payment or invoice is created.')]
    public function renewSubscription(RenewPlatformSubscriptionRequest $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->renew($this->identity($request), $school, $request->validated()));
    }

    #[Endpoint(title: 'Cancel a school subscription', description: 'Cancels the current subscription lifecycle while retaining historical records and without mutating school membership or context.')]
    public function cancelSubscription(Request $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->cancel($this->identity($request), $school));
    }

    #[Endpoint(title: 'Reopen a subscription grace period', description: 'Creates a bounded server-controlled grace state for an expired lifecycle. This does not collect payment or automate school suspension.')]
    public function reopenSubscriptionGrace(ReopenPlatformSubscriptionGraceRequest $request, string $school, PlatformSubscriptionLifecycleAction $action): JsonResponse
    {
        return ApiResponse::data($action->reopenGrace($this->identity($request), $school, $request->validated()));
    }

    private function identity(Request $request): Authenticatable
    {
        $identity = $request->user();
        abort_unless($identity instanceof Authenticatable, 401);

        return $identity;
    }
}
