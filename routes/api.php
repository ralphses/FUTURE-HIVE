<?php

use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicPeriodController;
use App\Contexts\Identity\Http\Controllers\Api\V1\AuthenticationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\ContactVerificationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\PasswordController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolContextController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolInvitationController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolMembershipController;
use App\Contexts\Identity\Http\Controllers\Api\V1\SchoolRoleController;
use App\Contexts\Platform\Http\Controllers\Api\V1\HealthController;
use App\Contexts\Platform\Http\Controllers\Api\V1\ReadinessController;
use App\Contexts\Platform\Http\Controllers\Api\V1\SchoolLifecycleController;
use App\Contexts\Platform\Http\Controllers\Api\V1\SchoolProfileController;
use App\Contexts\Platform\Http\Controllers\Api\V1\SchoolRegistrationController;
use App\Contexts\Platform\Http\Controllers\Api\V1\SchoolRegistrationVerificationController;
use App\Contexts\Platform\Http\Controllers\Api\V1\SchoolSetupController;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\RefreshCookieCsrf;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\RequireSchoolLifecycleContext;
use App\Support\Files\CloudinaryFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');
Route::get('/health/readiness', ReadinessController::class)->name('api.v1.health.readiness');
Route::get('/files/download', CloudinaryFileDownloadController::class)
    ->middleware([JwtAuthenticate::class, RequireSchoolContext::class, 'signed'])
    ->name('api.v1.files.download');

Route::post('/public/school-registrations', [SchoolRegistrationController::class, 'store'])
    ->middleware('throttle:public-school-registration')
    ->name('api.v1.public.school-registrations');

Route::post('/public/school-registrations/{registration}/verification/request', [SchoolRegistrationVerificationController::class, 'request'])
    ->middleware('throttle:public-registration-verification-request')
    ->name('api.v1.public.school-registrations.verification.request');

Route::post('/public/school-registrations/{registration}/verification/confirm', [SchoolRegistrationVerificationController::class, 'confirm'])
    ->middleware('throttle:public-registration-verification-confirm')
    ->name('api.v1.public.school-registrations.verification.confirm');

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
    Route::get('/schools/{school}/setup', [SchoolSetupController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.setup.index');
    Route::patch('/schools/{school}/setup/{item}', [SchoolSetupController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.setup.update');
    Route::get('/schools/{school}/profile', [SchoolProfileController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.profile.show');
    Route::put('/schools/{school}/profile', [SchoolProfileController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.profile.update');
    Route::post('/schools/{school}/profile/logo', [SchoolProfileController::class, 'uploadLogo'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.profile.logo.upload');
    Route::delete('/schools/{school}/profile/logo', [SchoolProfileController::class, 'removeLogo'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.profile.logo.remove');
    Route::get('/schools/{school}/lifecycle', [SchoolLifecycleController::class, 'show'])
        ->middleware(RequireSchoolLifecycleContext::class)
        ->name('api.v1.schools.lifecycle.show');
    Route::post('/schools/{school}/lifecycle/suspend', [SchoolLifecycleController::class, 'suspend'])
        ->middleware(RequireSchoolLifecycleContext::class)
        ->name('api.v1.schools.lifecycle.suspend');
    Route::post('/schools/{school}/lifecycle/reactivate', [SchoolLifecycleController::class, 'reactivate'])
        ->middleware(RequireSchoolLifecycleContext::class)
        ->name('api.v1.schools.lifecycle.reactivate');
    Route::post('/schools/{school}/lifecycle/archive', [SchoolLifecycleController::class, 'archive'])
        ->middleware(RequireSchoolLifecycleContext::class)
        ->name('api.v1.schools.lifecycle.archive');
    Route::get('/schools/{school}/academic-sessions', [AcademicPeriodController::class, 'sessions'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.index');
    Route::post('/schools/{school}/academic-sessions', [AcademicPeriodController::class, 'storeSession'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.store');
    Route::get('/schools/{school}/academic-sessions/{session}', [AcademicPeriodController::class, 'showSession'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.show');
    Route::patch('/schools/{school}/academic-sessions/{session}', [AcademicPeriodController::class, 'updateSession'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.update');
    Route::post('/schools/{school}/academic-sessions/{session}/activate', [AcademicPeriodController::class, 'activateSession'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.activate');
    Route::post('/schools/{school}/academic-sessions/{session}/close', [AcademicPeriodController::class, 'closeSession'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.close');
    Route::get('/schools/{school}/academic-sessions/{session}/terms', [AcademicPeriodController::class, 'terms'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.index');
    Route::post('/schools/{school}/academic-sessions/{session}/terms', [AcademicPeriodController::class, 'storeTerm'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.store');
    Route::patch('/schools/{school}/academic-sessions/{session}/terms/{term}', [AcademicPeriodController::class, 'updateTerm'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.update');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/activate', [AcademicPeriodController::class, 'activateTerm'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.activate');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/close', [AcademicPeriodController::class, 'closeTerm'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.close');
    Route::get('/schools/{school}/academic-context', [AcademicPeriodController::class, 'context'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-context');
});
