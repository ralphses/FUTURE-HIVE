<?php

use App\Contexts\Identity\Http\Controllers\Api\V1\AuthenticationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\ContactVerificationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\PasswordController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolContextController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolInvitationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolMembershipController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolRoleController;
use App\Contexts\Platform\Http\Controllers\Api\V1\HealthController;
use App\Contexts\Platform\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\RefreshCookieCsrf;
use App\Http\Middleware\RequireSchoolContext;
use App\Support\Files\CloudinaryFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');
Route::get('/health/readiness', ReadinessController::class)->name('api.v1.health.readiness');
Route::get('/files/download', CloudinaryFileDownloadController::class)
    ->middleware('signed')
    ->name('api.v1.files.download');

Route::post('/auth/login', [AuthenticationController::class, 'login'])
    ->middleware('throttle:auth-login')
    ->name('api.v1.auth.login');
Route::post('/auth/refresh', [AuthenticationController::class, 'refresh'])
    ->middleware(['throttle:auth-refresh', RefreshCookieCsrf::class])
    ->name('api.v1.auth.refresh');
Route::post('/auth/password/forgot', [PasswordController::class, 'forgot'])
    ->middleware('throttle:auth-password-forgot')
    ->name('api.v1.auth.password.forgot');
Route::post('/auth/password/reset', [PasswordController::class, 'reset'])
    ->middleware('throttle:auth-password-reset')
    ->name('api.v1.auth.password.reset');
Route::post('/auth/verification/request', [ContactVerificationController::class, 'request'])
    ->middleware('throttle:auth-verification-request')
    ->name('api.v1.auth.verification.request');
Route::post('/auth/verification/confirm', [ContactVerificationController::class, 'confirm'])
    ->middleware('throttle:auth-verification-confirm')
    ->name('api.v1.auth.verification.confirm');
Route::middleware(JwtAuthenticate::class)->group(function (): void {
    Route::get('/auth/me', [AuthenticationController::class, 'me'])->name('api.v1.auth.me');
    Route::post('/auth/logout', [AuthenticationController::class, 'logout'])->name('api.v1.auth.logout');
    Route::post('/auth/logout-all', [AuthenticationController::class, 'logoutAll'])->name('api.v1.auth.logout_all');
    Route::post('/auth/context/switch', [SchoolContextController::class, 'select'])->name('api.v1.auth.context.switch');
    Route::get('/auth/context', [SchoolContextController::class, 'show'])->name('api.v1.auth.context');
    Route::post('/auth/password/change', [PasswordController::class, 'change'])
        ->middleware('throttle:auth-password-change')
        ->name('api.v1.auth.password.change');
    Route::get('/me/memberships', [SchoolMembershipController::class, 'index'])
        ->name('api.v1.me.memberships');
    Route::post('/schools/{school}/invitations', [SchoolInvitationController::class, 'create'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.invitations.create');
    Route::post('/invitations/{invitation}/accept', [SchoolInvitationController::class, 'accept'])
        ->name('api.v1.invitations.accept');
    Route::post('/invitations/{invitation}/revoke', [SchoolInvitationController::class, 'revoke'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.invitations.revoke');
    Route::get('/schools/{school}/roles', [SchoolRoleController::class, 'catalogue'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.roles.catalogue');
    Route::get('/schools/{school}/memberships/{membership}/roles', [SchoolRoleController::class, 'membership'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.memberships.roles');
    Route::post('/schools/{school}/memberships/{membership}/roles', [SchoolRoleController::class, 'assign'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.memberships.roles.assign');
    Route::delete('/schools/{school}/memberships/{membership}/roles/{role}', [SchoolRoleController::class, 'revoke'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.memberships.roles.revoke');
    Route::get('/me/schools/{school}/permissions', [SchoolRoleController::class, 'permissions'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.me.schools.permissions');
});
