<?php

namespace App\Providers;

use App\Contexts\Academic\Application\Actions\AcademicScoreImportAction;
use App\Contexts\Finance\Application\Contracts\PaymentIntentGateway;
use App\Contexts\Finance\Application\Contracts\PaymentWebhookVerifier;
use App\Contexts\Finance\Application\Contracts\ReminderDelivery;
use App\Contexts\Finance\Infrastructure\Payments\DeterministicPaymentIntentGateway;
use App\Contexts\Finance\Infrastructure\Payments\DeterministicPaymentWebhookVerifier;
use App\Contexts\Finance\Infrastructure\Reminders\DeterministicReminderDelivery;
use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Infrastructure\ContactVerification\UnavailableContactVerificationCodeDelivery;
use App\Contexts\Identity\Infrastructure\PasswordReset\UnavailablePasswordResetCodeDelivery;
use App\Contexts\Platform\Application\Contracts\ImportCanonicalRecordWriter;
use App\Contexts\Platform\Application\Contracts\ImportSpecializedCommitter;
use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Infrastructure\Verification\UnavailableRegistrationVerificationCodeDelivery;
use App\Contexts\Registry\Application\Contracts\GuardianInvitationCodeDelivery;
use App\Contexts\Registry\Application\Services\RegistryImportCanonicalRecordWriter;
use App\Contexts\Registry\Infrastructure\Invitations\UnavailableGuardianInvitationCodeDelivery;
use App\Support\Contacts\ContactNormalizer;
use App\Support\Contacts\IdentityContactNormalizer;
use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\CloudinarySdkClient;
use App\Support\Files\MalwareScanner;
use App\Support\Files\UnavailableMalwareScanner;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\StructuredLogMetricsRecorder;
use App\Support\OpenApi\FoundationContractDocumentTransformer;
use Cloudinary\Api\Upload\UploadApi;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use libphonenumber\PhoneNumberUtil;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImportCanonicalRecordWriter::class, RegistryImportCanonicalRecordWriter::class);
        $this->app->bind(ImportSpecializedCommitter::class, AcademicScoreImportAction::class);
        $this->app->singleton(PaymentIntentGateway::class, DeterministicPaymentIntentGateway::class);
        $this->app->singleton(PaymentWebhookVerifier::class, DeterministicPaymentWebhookVerifier::class);
        $this->app->singleton(ReminderDelivery::class, DeterministicReminderDelivery::class);
        $this->app->singleton(
            CloudinaryAssetClient::class,
            static fn (): CloudinarySdkClient => new CloudinarySdkClient(
                new UploadApi(config('services.cloudinary.url')),
            ),
        );
        $this->app->singleton(MalwareScanner::class, UnavailableMalwareScanner::class);
        $this->app->singleton(PasswordResetCodeDelivery::class, UnavailablePasswordResetCodeDelivery::class);
        $this->app->singleton(ContactVerificationCodeDelivery::class, UnavailableContactVerificationCodeDelivery::class);
        $this->app->singleton(RegistrationVerificationCodeDelivery::class, UnavailableRegistrationVerificationCodeDelivery::class);
        $this->app->singleton(GuardianInvitationCodeDelivery::class, UnavailableGuardianInvitationCodeDelivery::class);
        $this->app->singleton(MetricsRecorder::class, StructuredLogMetricsRecorder::class);
        $this->app->singleton(PhoneNumberUtil::class, static fn (): PhoneNumberUtil => PhoneNumberUtil::getInstance());
        $this->app->singleton(ContactNormalizer::class, IdentityContactNormalizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', static function (Request $request): Limit {
            return Limit::perMinute(5)->by(hash('sha256', mb_strtolower(trim($request->string('login')->toString())).'|'.$request->ip()));
        });

        RateLimiter::for('auth-refresh', static function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('auth-password-forgot', static function (Request $request): Limit {
            return Limit::perMinute(5)->by(hash('sha256', mb_strtolower(trim($request->string('login')->toString())).'|'.$request->ip()));
        });

        RateLimiter::for('auth-password-reset', static function (Request $request): Limit {
            return Limit::perMinute(10)->by(hash('sha256', mb_strtolower(trim($request->string('login')->toString())).'|'.$request->ip()));
        });

        RateLimiter::for('auth-password-change', static function (Request $request): Limit {
            return Limit::perMinute(5)->by(($request->user()?->getAuthIdentifier() ?? 'anonymous').'|'.$request->ip());
        });

        RateLimiter::for('auth-verification-request', static function (Request $request): Limit {
            return Limit::perMinutes(15, 3)->by(hash('sha256', mb_strtolower(trim($request->string('contact')->toString())).'|'.$request->ip()));
        });

        RateLimiter::for('auth-verification-confirm', static function (Request $request): Limit {
            return Limit::perMinute(10)->by(hash('sha256', mb_strtolower(trim($request->string('contact')->toString())).'|'.$request->ip()));
        });

        RateLimiter::for('public-school-registration', static function (Request $request): Limit {
            $contact = trim($request->string('contact')->toString());

            try {
                $contact = app(ContactNormalizer::class)->normalize($contact)->value;
            } catch (Throwable) {
                // Invalid contacts still receive a bounded, non-plaintext throttle key.
            }

            return Limit::perMinutes(15, 3)->by(hash('sha256', mb_strtolower($contact).'|'.$request->ip()));
        });

        RateLimiter::for('public-registration-verification-request', static function (Request $request): Limit {
            return Limit::perMinutes(15, 3)->by($request->route('registration').'|'.$request->ip());
        });

        RateLimiter::for('public-registration-verification-confirm', static function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->route('registration').'|'.$request->ip());
        });

        if (class_exists(Scramble::class)) {
            Scramble::configure()->routes(static fn (Route $route): bool => in_array(
                $route->getName(),
                [
                    'api.v1.health',
                    'api.v1.health.readiness',
                    'api.v1.files.download',
                    'api.v1.auth.login',
                    'api.v1.auth.refresh',
                    'api.v1.auth.me',
                    'api.v1.auth.logout',
                    'api.v1.auth.logout_all',
                    'api.v1.auth.context.switch',
                    'api.v1.auth.context',
                    'api.v1.auth.password.forgot',
                    'api.v1.auth.password.reset',
                    'api.v1.auth.password.change',
                    'api.v1.auth.verification.request',
                    'api.v1.auth.verification.confirm',
                    'api.v1.me.memberships',
                    'api.v1.schools.invitations.create',
                    'api.v1.invitations.accept',
                    'api.v1.invitations.revoke',
                    'api.v1.schools.roles.catalogue',
                    'api.v1.schools.memberships.roles',
                    'api.v1.schools.memberships.roles.assign',
                    'api.v1.schools.memberships.roles.revoke',
                    'api.v1.me.schools.permissions',
                    'api.v1.schools.setup.index',
                    'api.v1.schools.setup.update',
                    'api.v1.schools.profile.show',
                    'api.v1.schools.profile.update',
                    'api.v1.schools.profile.logo.upload',
                    'api.v1.schools.profile.logo.remove',
                    'api.v1.schools.lifecycle.show',
                    'api.v1.schools.lifecycle.suspend',
                    'api.v1.schools.lifecycle.reactivate',
                    'api.v1.schools.lifecycle.archive',
                    'api.v1.schools.staff.index',
                    'api.v1.schools.staff.store',
                    'api.v1.schools.staff.show',
                    'api.v1.schools.staff.update',
                    'api.v1.schools.staff.activate',
                    'api.v1.schools.staff.suspend',
                    'api.v1.schools.staff.end',
                    'api.v1.schools.import-templates.index',
                    'api.v1.schools.import-templates.download',
                    'api.v1.schools.imports.store',
                    'api.v1.schools.imports.index',
                    'api.v1.schools.imports.show',
                    'api.v1.schools.imports.cancel',
                    'api.v1.schools.imports.mapping.show',
                    'api.v1.schools.imports.mapping.replace',
                    'api.v1.schools.imports.preview',
                    'api.v1.schools.imports.validation.validate',
                    'api.v1.schools.imports.validation.show',
                    'api.v1.schools.imports.duplicates.detect',
                    'api.v1.schools.imports.duplicates.show',
                    'api.v1.schools.imports.duplicates.resolve',
                    'api.v1.schools.imports.dry-run.generate',
                    'api.v1.schools.imports.dry-run.show',
                    'api.v1.schools.imports.commit.generate',
                    'api.v1.schools.imports.commit.show',
                    'api.v1.schools.imports.recovery.retry',
                    'api.v1.schools.imports.recovery.show',
                    'api.v1.schools.imports.historical-academic.store',
                    'api.v1.schools.imports.historical-academic.show',
                    'api.v1.schools.imports.financial-opening-balances.store',
                    'api.v1.schools.imports.financial-opening-balances.show',
                    'api.v1.schools.fee-categories.index',
                    'api.v1.schools.fee-categories.store',
                    'api.v1.schools.fee-categories.update',
                    'api.v1.schools.fee-plans.index',
                    'api.v1.schools.fee-plans.store',
                    'api.v1.schools.fee-plans.show',
                    'api.v1.schools.fee-plans.update',
                    'api.v1.schools.fee-plans.activate',
                    'api.v1.schools.fee-plans.retire',
                    'api.v1.schools.academic-sessions.terms.fee-assessments.index',
                    'api.v1.schools.academic-sessions.terms.fee-assessments.store',
                    'api.v1.schools.academic-sessions.terms.fee-assessments.show',
                    'api.v1.schools.fee-assessments.finalize',
                    'api.v1.schools.invoices.index',
                    'api.v1.schools.invoices.show',
                    'api.v1.schools.fee-adjustment-programs.index',
                    'api.v1.schools.fee-adjustment-programs.store',
                    'api.v1.schools.fee-adjustment-programs.update',
                    'api.v1.schools.fee-adjustment-programs.activate',
                    'api.v1.schools.fee-adjustment-programs.retire',
                    'api.v1.schools.invoices.adjustments.index',
                    'api.v1.schools.invoices.adjustments.store',
                    'api.v1.schools.invoices.adjustments.approve',
                    'api.v1.schools.invoices.adjustments.reject',
                    'api.v1.schools.invoices.adjustments.apply',
                    'api.v1.schools.invoices.adjustments.cancel',
                    'api.v1.schools.family-billing.index',
                    'api.v1.schools.family-billing.show',
                    'api.v1.me.family-billing',
                    'api.v1.schools.invoices.payment-allocations.index',
                    'api.v1.schools.invoices.payment-allocations.store',
                    'api.v1.schools.invoices.payment-allocations.show',
                    'api.v1.schools.invoices.payment-intents.index',
                    'api.v1.schools.invoices.payment-intents.store',
                    'api.v1.schools.invoices.payment-intents.show',
                    'api.v1.schools.invoices.payment-intents.cancel',
                    'api.v1.schools.invoices.payment-evidence.index',
                    'api.v1.schools.invoices.payment-evidence.store',
                    'api.v1.schools.invoices.payment-evidence.show',
                    'api.v1.schools.invoices.payment-evidence.verify',
                    'api.v1.schools.invoices.payment-evidence.reject',
                    'api.v1.schools.invoices.payment-evidence.cancel',
                    'api.v1.schools.ledger.index',
                    'api.v1.schools.ledger.store',
                    'api.v1.schools.ledger.show',
                    'api.v1.schools.ledger.post',
                    'api.v1.schools.receipts.index',
                    'api.v1.schools.receipts.store',
                    'api.v1.schools.receipts.show',
                    'api.v1.schools.reversals.index',
                    'api.v1.schools.reversals.store',
                    'api.v1.schools.reversals.show',
                    'api.v1.schools.reversals.approve',
                    'api.v1.schools.reversals.reject',
                    'api.v1.schools.reversals.apply',
                    'api.v1.schools.refunds.index',
                    'api.v1.schools.refunds.store',
                    'api.v1.schools.refunds.show',
                    'api.v1.schools.refunds.approve',
                    'api.v1.schools.refunds.reject',
                    'api.v1.schools.refunds.apply',
                    'api.v1.schools.reconciliations.index',
                    'api.v1.schools.reconciliations.store',
                    'api.v1.schools.reconciliations.show',
                    'api.v1.schools.reconciliations.items',
                    'api.v1.schools.reconciliations.resolve',
                    'api.v1.schools.reconciliations.cancel',
                    'api.v1.schools.financial-statements.index',
                    'api.v1.schools.students.financial-statement',
                    'api.v1.schools.guardians.financial-statement',
                    'api.v1.schools.finance-reports.collections',
                    'api.v1.schools.finance-reports.arrears',
                    'api.v1.schools.finance-reports.fee-plans',
                    'api.v1.schools.finance-reports.export',
                    'api.v1.schools.financial-periods.index',
                    'api.v1.schools.financial-periods.show',
                    'api.v1.schools.financial-periods.close.start',
                    'api.v1.schools.financial-periods.close.complete',
                    'api.v1.schools.financial-periods.reopen',
                    'api.v1.schools.financial-period-adjustments.index',
                    'api.v1.schools.financial-period-adjustments.store',
                    'api.v1.schools.financial-period-adjustments.show',
                    'api.v1.schools.financial-period-adjustments.approve',
                    'api.v1.schools.financial-period-adjustments.reject',
                    'api.v1.schools.financial-period-adjustments.apply',
                    'api.v1.schools.fee-reminder-candidates.index',
                    'api.v1.schools.fee-reminders.store',
                    'api.v1.schools.fee-reminders.show',
                    'api.v1.schools.fee-reminders.send',
                    'api.v1.schools.fee-reminders.cancel',
                    'api.v1.payment-webhooks.verify',
                    'api.v1.schools.imports.audit.show',
                    'api.v1.schools.imports.report.download',
                    'api.v1.schools.class-teacher-assignments.index',
                    'api.v1.schools.class-teacher-assignments.store',
                    'api.v1.schools.class-teacher-assignments.show',
                    'api.v1.schools.class-teacher-assignments.update',
                    'api.v1.schools.class-teacher-assignments.revoke',
                    'api.v1.schools.teacher-worklist.index',
                    'api.v1.schools.attendance.show',
                    'api.v1.schools.attendance.replace',
                    'api.v1.schools.attendance.submit',
                    'api.v1.schools.attendance.lock',
                    'api.v1.schools.attendance.submission',
                    'api.v1.schools.attendance.corrections.index',
                    'api.v1.schools.attendance.corrections.store',
                    'api.v1.schools.attendance.report',
                    'api.v1.schools.teaching.schemes.show',
                    'api.v1.schools.teaching.schemes.store',
                    'api.v1.schools.teaching.schemes.topics.index',
                    'api.v1.schools.teaching.schemes.topics.store',
                    'api.v1.schools.teaching.schemes.topics.update',
                    'api.v1.schools.teaching.schemes.topics.progress',
                    'api.v1.schools.teaching.lessons.index',
                    'api.v1.schools.teaching.lessons.store',
                    'api.v1.schools.teaching.lessons.show',
                    'api.v1.schools.teaching.lessons.update',
                    'api.v1.schools.teaching.lessons.submit',
                    'api.v1.schools.teaching.lessons.approve',
                    'api.v1.schools.teaching.lessons.return',
                    'api.v1.schools.announcements.index',
                    'api.v1.schools.announcements.store',
                    'api.v1.schools.announcements.show',
                    'api.v1.schools.announcements.update',
                    'api.v1.schools.announcements.submit',
                    'api.v1.schools.announcements.approve',
                    'api.v1.schools.announcements.publish',
                    'api.v1.schools.announcements.recall',
                    'api.v1.schools.announcements.reject',
                    'api.v1.schools.academic-sessions.index',
                    'api.v1.schools.academic-sessions.store',
                    'api.v1.schools.academic-sessions.show',
                    'api.v1.schools.academic-sessions.update',
                    'api.v1.schools.academic-sessions.activate',
                    'api.v1.schools.academic-sessions.close',
                    'api.v1.schools.academic-sessions.terms.index',
                    'api.v1.schools.academic-sessions.terms.store',
                    'api.v1.schools.academic-sessions.terms.update',
                    'api.v1.schools.academic-sessions.terms.activate',
                    'api.v1.schools.academic-sessions.terms.close',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.activate',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.deactivate',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.teaching-assignments.revoke',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-scheme.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.activate',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.retire',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.activate',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-policies.grading-scales.retire',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.open',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.close',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.cancel',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.access.show',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.access.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.access.activate',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.access.close',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.index',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.update',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.update-one',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.audit-history',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.entry-audit-history',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.score-imports.store',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submit',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submission',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submission.review',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submission.return',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submission.approve',
                    'api.v1.schools.academic-sessions.terms.subject-offerings.assessment-instances.scores.submission.resubmit',
                    'api.v1.schools.academic-sessions.terms.result-runs.store',
                    'api.v1.schools.academic-sessions.terms.result-runs.index',
                    'api.v1.schools.academic-sessions.terms.result-runs.show',
                    'api.v1.schools.academic-sessions.terms.result-runs.students.show',
                    'api.v1.schools.academic-sessions.terms.result-completeness',
                    'api.v1.schools.academic-sessions.terms.result-review-queue.index',
                    'api.v1.schools.academic-sessions.terms.result-review-queue.show',
                    'api.v1.schools.promotion-rules.index',
                    'api.v1.schools.promotion-rules.store',
                    'api.v1.schools.promotion-rules.show',
                    'api.v1.schools.promotion-rules.update',
                    'api.v1.schools.promotion-rules.activate',
                    'api.v1.schools.promotion-rules.deactivate',
                    'api.v1.schools.academic-context',
                    'api.v1.schools.academic-readiness',
                    'api.v1.schools.students.index',
                    'api.v1.schools.students.store',
                    'api.v1.schools.students.show',
                    'api.v1.schools.students.update',
                    'api.v1.schools.students.activate',
                    'api.v1.schools.students.withdraw',
                    'api.v1.schools.students.search',
                    'api.v1.schools.students.export',
                    'api.v1.schools.students.audit-history',
                    'api.v1.schools.students.enrollments.index',
                    'api.v1.schools.students.enrollments.store',
                    'api.v1.schools.students.enrollments.show',
                    'api.v1.schools.students.enrollments.end',
                    'api.v1.schools.students.enrollments.transfer',
                    'api.v1.schools.students.enrollments.withdraw',
                    'api.v1.schools.students.enrollment-history',
                    'api.v1.schools.promotion-cycles.index',
                    'api.v1.schools.promotion-cycles.store',
                    'api.v1.schools.promotion-cycles.show',
                    'api.v1.schools.promotion-cycles.decisions',
                    'api.v1.schools.promotion-cycles.approve',
                    'api.v1.schools.promotion-cycles.apply',
                    'api.v1.schools.promotion-cycles.rollback',
                    'api.v1.schools.students.profile.show',
                    'api.v1.schools.students.profile.update',
                    'api.v1.schools.students.documents.index',
                    'api.v1.schools.students.documents.store',
                    'api.v1.students.documents.download',
                    'api.v1.schools.students.documents.revoke',
                    'api.v1.schools.guardians.index',
                    'api.v1.schools.guardians.show',
                    'api.v1.schools.guardians.audit-history',
                    'api.v1.schools.students.guardian-relationships.index',
                    'api.v1.schools.students.guardian-relationships.store',
                    'api.v1.schools.students.guardian-relationships.update',
                    'api.v1.schools.students.guardian-relationships.revoke',
                    'api.v1.schools.students.guardian-relationships.invitation.request',
                    'api.v1.guardian-invitations.confirm',
                    'api.v1.schools.guardian-links.index',
                    'api.v1.schools.guardian-invitations.revoke',
                    'api.v1.schools.academic-levels.index',
                    'api.v1.schools.academic-levels.store',
                    'api.v1.schools.academic-levels.show',
                    'api.v1.schools.academic-levels.update',
                    'api.v1.schools.academic-levels.activate',
                    'api.v1.schools.academic-levels.deactivate',
                    'api.v1.schools.academic-levels.sections.index',
                    'api.v1.schools.academic-levels.sections.store',
                    'api.v1.schools.academic-levels.sections.show',
                    'api.v1.schools.academic-levels.sections.update',
                    'api.v1.schools.academic-levels.sections.activate',
                    'api.v1.schools.academic-levels.sections.deactivate',
                    'api.v1.schools.academic-levels.sections.class-arms.index',
                    'api.v1.schools.academic-levels.sections.class-arms.store',
                    'api.v1.schools.academic-levels.sections.class-arms.show',
                    'api.v1.schools.academic-levels.sections.class-arms.update',
                    'api.v1.schools.academic-levels.sections.class-arms.activate',
                    'api.v1.schools.academic-levels.sections.class-arms.deactivate',
                    'api.v1.schools.subjects.index',
                    'api.v1.schools.subjects.store',
                    'api.v1.schools.subjects.show',
                    'api.v1.schools.subjects.update',
                    'api.v1.schools.subjects.activate',
                    'api.v1.schools.subjects.deactivate',
                    'api.v1.schools.roles.catalogue',
                    'api.v1.schools.memberships.roles',
                    'api.v1.schools.memberships.roles.assign',
                    'api.v1.schools.memberships.roles.revoke',
                    'api.v1.me.schools.permissions',
                    'api.v1.public.school-registrations',
                    'api.v1.public.school-registrations.verification.request',
                    'api.v1.public.school-registrations.verification.confirm',
                    'api.v1.schools.academic-sessions.terms.result-runs.gate.show',
                    'api.v1.schools.academic-sessions.terms.result-runs.approve',
                    'api.v1.schools.academic-sessions.terms.result-runs.reject',
                    'api.v1.schools.academic-sessions.terms.result-runs.publish',
                    'api.v1.schools.academic-sessions.terms.result-runs.snapshot.store',
                    'api.v1.schools.academic-sessions.terms.result-snapshots.index',
                    'api.v1.schools.academic-sessions.terms.result-snapshots.show',
                    'api.v1.schools.academic-sessions.terms.result-snapshots.students.show',
                    'api.v1.schools.academic-sessions.terms.result-snapshots.corrections.store',
                    'api.v1.schools.academic-sessions.terms.result-snapshots.corrections.index',
                    'api.v1.schools.academic-sessions.terms.result-corrections.show',
                    'api.v1.schools.academic-sessions.terms.result-corrections.approve',
                    'api.v1.schools.academic-sessions.terms.result-corrections.reject',
                    'api.v1.schools.academic-sessions.terms.result-corrections.republish',
                    'api.v1.schools.academic-sessions.terms.result-history.index',
                    'api.v1.schools.academic-sessions.terms.students.result-history',
                    'api.v1.schools.academic-sessions.terms.result-exports',
                    'api.v1.schools.academic-sessions.terms.ranking-statistics-policies.index',
                    'api.v1.schools.academic-sessions.terms.ranking-statistics-policies.store',
                    'api.v1.schools.academic-sessions.terms.ranking-statistics-policies.show',
                    'api.v1.schools.academic-sessions.terms.ranking-statistics-policies.activate',
                    'api.v1.schools.academic-sessions.terms.ranking-statistics-policies.retire',
                    'api.v1.schools.academic-sessions.terms.promotion-eligibility.index',
                    'api.v1.schools.academic-sessions.terms.promotion-eligibility.student',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.index',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.store',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.show',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.update',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.activate',
                    'api.v1.schools.academic-sessions.terms.report-card-templates.retire',
                ],
                true,
            ));
            Scramble::configure()->afterOpenApiGenerated(new FoundationContractDocumentTransformer);
        }

        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            app(MetricsRecorder::class)->increment('queue.jobs.failed', [
                'job' => $event->job->resolveName(),
            ]);
        });
    }
}
