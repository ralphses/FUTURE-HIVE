<?php

use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicAssessmentPolicyVersionController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicAssessmentSchemeController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicClassArmController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicGradingScaleController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicPeriodController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicPromotionRuleController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicReadinessController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicStructureController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicSubjectController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicSubjectOfferingController;
use App\Contexts\Academic\Http\Controllers\Api\V1\AcademicTeachingAssignmentController;
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
use App\Contexts\Registry\Http\Controllers\Api\V1\GuardianInvitationController;
use App\Contexts\Registry\Http\Controllers\Api\V1\GuardianRelationshipController;
use App\Contexts\Registry\Http\Controllers\Api\V1\StaffProfileController;
use App\Contexts\Registry\Http\Controllers\Api\V1\StudentController;
use App\Contexts\Registry\Http\Controllers\Api\V1\StudentEnrollmentController;
use App\Contexts\Registry\Http\Controllers\Api\V1\StudentProfileController;
use App\Contexts\Registry\Http\Controllers\Api\V1\StudentPromotionController;
use App\Http\Middleware\JwtAuthenticate;
use App\Http\Middleware\RefreshCookieCsrf;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\RequireSchoolLifecycleContext;
use App\Support\Files\CloudinaryFileDownloadController;
use Illuminate\Support\Facades\Route;

// Platform: liveness, readiness and private file delivery.
Route::get('/health', HealthController::class)->name('api.v1.health');
Route::get('/health/readiness', ReadinessController::class)->name('api.v1.health.readiness');
Route::get('/files/download', CloudinaryFileDownloadController::class)
    ->middleware([JwtAuthenticate::class, RequireSchoolContext::class, 'signed'])
    ->name('api.v1.files.download');

// School Registration: public provisional intake and contact verification.
Route::post('/public/school-registrations', [SchoolRegistrationController::class, 'store'])
    ->middleware('throttle:public-school-registration')
    ->name('api.v1.public.school-registrations');

Route::post('/public/school-registrations/{registration}/verification/request', [SchoolRegistrationVerificationController::class, 'request'])
    ->middleware('throttle:public-registration-verification-request')
    ->name('api.v1.public.school-registrations.verification.request');

Route::post('/public/school-registrations/{registration}/verification/confirm', [SchoolRegistrationVerificationController::class, 'confirm'])
    ->middleware('throttle:public-registration-verification-confirm')
    ->name('api.v1.public.school-registrations.verification.confirm');

// Identity & Authentication: sign-in, sessions, credentials and contact verification.
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
    // Identity & Authentication: authenticated identity and session operations.
    Route::get('/auth/me', [AuthenticationController::class, 'me'])->name('api.v1.auth.me');
    Route::post('/auth/logout', [AuthenticationController::class, 'logout'])->name('api.v1.auth.logout');
    Route::post('/auth/logout-all', [AuthenticationController::class, 'logoutAll'])->name('api.v1.auth.logout_all');
    Route::post('/auth/context/switch', [SchoolContextController::class, 'select'])->name('api.v1.auth.context.switch');
    Route::get('/auth/context', [SchoolContextController::class, 'show'])->name('api.v1.auth.context');
    Route::post('/auth/password/change', [PasswordController::class, 'change'])
        ->middleware('throttle:auth-password-change')
        ->name('api.v1.auth.password.change');

    // School Context & Memberships: membership, invitation, role and permission access.
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
    Route::post('/guardian-invitations/{invitation}/confirm', [GuardianInvitationController::class, 'confirm'])
        ->name('api.v1.guardian-invitations.confirm');
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

    // School Administration: setup progress, profile/branding and lifecycle controls.
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
    Route::get('/schools/{school}/staff', [StaffProfileController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.index');
    Route::post('/schools/{school}/staff', [StaffProfileController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.store');
    Route::get('/schools/{school}/staff/{staff}', [StaffProfileController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.show');
    Route::patch('/schools/{school}/staff/{staff}', [StaffProfileController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.update');
    Route::post('/schools/{school}/staff/{staff}/activate', [StaffProfileController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.activate');
    Route::post('/schools/{school}/staff/{staff}/suspend', [StaffProfileController::class, 'suspend'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.suspend');
    Route::post('/schools/{school}/staff/{staff}/end', [StaffProfileController::class, 'end'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.staff.end');

    // Academic Structure and Assessment: periods, offerings, assignments, policies and scales.
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
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings', [AcademicSubjectOfferingController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.index');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings', [AcademicSubjectOfferingController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.store');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}', [AcademicSubjectOfferingController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.show');
    Route::patch('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}', [AcademicSubjectOfferingController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.update');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/activate', [AcademicSubjectOfferingController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.activate');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/deactivate', [AcademicSubjectOfferingController::class, 'deactivate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.deactivate');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments', [AcademicTeachingAssignmentController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.index');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments', [AcademicTeachingAssignmentController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.store');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}', [AcademicTeachingAssignmentController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.show');
    Route::patch('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}', [AcademicTeachingAssignmentController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.update');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/teaching-assignments/{assignment}/revoke', [AcademicTeachingAssignmentController::class, 'revoke'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.revoke');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-scheme', [AcademicAssessmentSchemeController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show');
    Route::put('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-scheme', [AcademicAssessmentSchemeController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies', [AcademicAssessmentPolicyVersionController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}', [AcademicAssessmentPolicyVersionController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies', [AcademicAssessmentPolicyVersionController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/activate', [AcademicAssessmentPolicyVersionController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/retire', [AcademicAssessmentPolicyVersionController::class, 'retire'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales', [AcademicGradingScaleController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index');
    Route::get('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}', [AcademicGradingScaleController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales', [AcademicGradingScaleController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/activate', [AcademicGradingScaleController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate');
    Route::post('/schools/{school}/academic-sessions/{session}/terms/{term}/subject-offerings/{offering}/assessment-policies/{policy}/grading-scales/{scale}/retire', [AcademicGradingScaleController::class, 'retire'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire');
    Route::get('/schools/{school}/academic-context', [AcademicPeriodController::class, 'context'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-context');
    Route::get('/schools/{school}/academic-readiness', AcademicReadinessController::class)
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.academic-readiness');

    // Student Registry: admissions, profiles, private documents and guardian relationships.
    Route::get('/schools/{school}/students', [StudentController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.index');
    Route::post('/schools/{school}/students', [StudentController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.store');
    Route::get('/schools/{school}/students/{student}', [StudentController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.show');
    Route::patch('/schools/{school}/students/{student}', [StudentController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.update');
    Route::post('/schools/{school}/students/{student}/activate', [StudentController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.activate');
    Route::post('/schools/{school}/students/{student}/withdraw', [StudentController::class, 'withdraw'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.withdraw');
    Route::get('/schools/{school}/students/{student}/enrollments', [StudentEnrollmentController::class, 'index'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.index');
    Route::post('/schools/{school}/students/{student}/enrollments', [StudentEnrollmentController::class, 'store'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.store');
    Route::get('/schools/{school}/students/{student}/enrollments/{enrollment}', [StudentEnrollmentController::class, 'show'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.show');
    Route::post('/schools/{school}/students/{student}/enrollments/{enrollment}/end', [StudentEnrollmentController::class, 'end'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.end');
    Route::post('/schools/{school}/students/{student}/enrollments/{enrollment}/transfer', [StudentEnrollmentController::class, 'transfer'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.transfer');
    Route::post('/schools/{school}/students/{student}/enrollments/{enrollment}/withdraw', [StudentEnrollmentController::class, 'withdraw'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollments.withdraw');
    Route::get('/schools/{school}/students/{student}/enrollment-history', [StudentEnrollmentController::class, 'history'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.enrollment-history');
    Route::get('/schools/{school}/promotion-cycles', [StudentPromotionController::class, 'index'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.index');
    Route::post('/schools/{school}/promotion-cycles', [StudentPromotionController::class, 'store'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.store');
    Route::get('/schools/{school}/promotion-cycles/{cycle}', [StudentPromotionController::class, 'show'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.show');
    Route::post('/schools/{school}/promotion-cycles/{cycle}/decisions', [StudentPromotionController::class, 'decision'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.decisions');
    Route::post('/schools/{school}/promotion-cycles/{cycle}/approve', [StudentPromotionController::class, 'approve'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.approve');
    Route::post('/schools/{school}/promotion-cycles/{cycle}/apply', [StudentPromotionController::class, 'apply'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.apply');
    Route::post('/schools/{school}/promotion-cycles/{cycle}/rollback', [StudentPromotionController::class, 'rollback'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-cycles.rollback');
    Route::get('/schools/{school}/students/{student}/profile', [StudentProfileController::class, 'showProfile'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.profile.show');
    Route::put('/schools/{school}/students/{student}/profile', [StudentProfileController::class, 'updateProfile'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.profile.update');
    Route::get('/schools/{school}/students/{student}/documents', [StudentProfileController::class, 'documents'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.documents.index');
    Route::post('/schools/{school}/students/{student}/documents', [StudentProfileController::class, 'uploadDocument'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.documents.store');
    Route::get('/schools/{school}/students/{student}/documents/{document}', [StudentProfileController::class, 'downloadDocument'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.students.documents.download');
    Route::post('/schools/{school}/students/{student}/documents/{document}/revoke', [StudentProfileController::class, 'revokeDocument'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.documents.revoke');
    Route::get('/schools/{school}/guardians', [GuardianRelationshipController::class, 'guardians'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.guardians.index');
    Route::get('/schools/{school}/guardians/{guardian}', [GuardianRelationshipController::class, 'showGuardian'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.guardians.show');
    Route::get('/schools/{school}/students/{student}/guardian-relationships', [GuardianRelationshipController::class, 'studentRelationships'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.guardian-relationships.index');
    Route::post('/schools/{school}/students/{student}/guardian-relationships', [GuardianRelationshipController::class, 'create'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.guardian-relationships.store');
    Route::patch('/schools/{school}/students/{student}/guardian-relationships/{relationship}', [GuardianRelationshipController::class, 'update'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.guardian-relationships.update');
    Route::post('/schools/{school}/students/{student}/guardian-relationships/{relationship}/revoke', [GuardianRelationshipController::class, 'revoke'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.guardian-relationships.revoke');
    Route::post('/schools/{school}/students/{student}/guardian-relationships/{relationship}/invitation', [GuardianInvitationController::class, 'request'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.students.guardian-relationships.invitation.request');
    Route::get('/me/guardian-links', [GuardianInvitationController::class, 'links'])
        ->name('api.v1.schools.guardian-links.index');
    Route::post('/schools/{school}/guardian-invitations/{invitation}/revoke', [GuardianInvitationController::class, 'revoke'])
        ->middleware(RequireSchoolContext::class)
        ->name('api.v1.schools.guardian-invitations.revoke');

    // Academic Structure: levels, sections, class arms and subject catalogues.
    Route::get('/schools/{school}/academic-levels', [AcademicStructureController::class, 'levels'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.index');
    Route::post('/schools/{school}/academic-levels', [AcademicStructureController::class, 'storeLevel'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.store');
    Route::get('/schools/{school}/academic-levels/{level}', [AcademicStructureController::class, 'showLevel'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.show');
    Route::patch('/schools/{school}/academic-levels/{level}', [AcademicStructureController::class, 'updateLevel'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.update');
    Route::post('/schools/{school}/academic-levels/{level}/activate', [AcademicStructureController::class, 'activateLevel'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.activate');
    Route::post('/schools/{school}/academic-levels/{level}/deactivate', [AcademicStructureController::class, 'deactivateLevel'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.deactivate');
    Route::get('/schools/{school}/academic-levels/{level}/sections', [AcademicStructureController::class, 'sections'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.index');
    Route::post('/schools/{school}/academic-levels/{level}/sections', [AcademicStructureController::class, 'storeSection'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.store');
    Route::get('/schools/{school}/academic-levels/{level}/sections/{section}', [AcademicStructureController::class, 'showSection'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.show');
    Route::patch('/schools/{school}/academic-levels/{level}/sections/{section}', [AcademicStructureController::class, 'updateSection'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.update');
    Route::post('/schools/{school}/academic-levels/{level}/sections/{section}/activate', [AcademicStructureController::class, 'activateSection'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.activate');
    Route::post('/schools/{school}/academic-levels/{level}/sections/{section}/deactivate', [AcademicStructureController::class, 'deactivateSection'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.deactivate');
    Route::get('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms', [AcademicClassArmController::class, 'index'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.index');
    Route::post('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms', [AcademicClassArmController::class, 'store'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.store');
    Route::get('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}', [AcademicClassArmController::class, 'show'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.show');
    Route::patch('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}', [AcademicClassArmController::class, 'update'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.update');
    Route::post('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/activate', [AcademicClassArmController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.activate');
    Route::post('/schools/{school}/academic-levels/{level}/sections/{section}/class-arms/{classArm}/deactivate', [AcademicClassArmController::class, 'deactivate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.academic-levels.sections.class-arms.deactivate');
    Route::get('/schools/{school}/subjects', [AcademicSubjectController::class, 'index'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.index');
    Route::post('/schools/{school}/subjects', [AcademicSubjectController::class, 'store'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.store');
    Route::get('/schools/{school}/subjects/{subject}', [AcademicSubjectController::class, 'show'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.show');
    Route::patch('/schools/{school}/subjects/{subject}', [AcademicSubjectController::class, 'update'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.update');
    Route::post('/schools/{school}/subjects/{subject}/activate', [AcademicSubjectController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.activate');
    Route::post('/schools/{school}/subjects/{subject}/deactivate', [AcademicSubjectController::class, 'deactivate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.subjects.deactivate');
    Route::get('/schools/{school}/promotion-rules', [AcademicPromotionRuleController::class, 'index'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.index');
    Route::post('/schools/{school}/promotion-rules', [AcademicPromotionRuleController::class, 'store'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.store');
    Route::get('/schools/{school}/promotion-rules/{rule}', [AcademicPromotionRuleController::class, 'show'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.show');
    Route::put('/schools/{school}/promotion-rules/{rule}', [AcademicPromotionRuleController::class, 'update'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.update');
    Route::post('/schools/{school}/promotion-rules/{rule}/activate', [AcademicPromotionRuleController::class, 'activate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.activate');
    Route::post('/schools/{school}/promotion-rules/{rule}/deactivate', [AcademicPromotionRuleController::class, 'deactivate'])
        ->middleware(RequireSchoolContext::class)->name('api.v1.schools.promotion-rules.deactivate');
});
