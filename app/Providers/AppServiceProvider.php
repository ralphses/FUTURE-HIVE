<?php

namespace App\Providers;

use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Infrastructure\ContactVerification\UnavailableContactVerificationCodeDelivery;
use App\Contexts\Identity\Infrastructure\PasswordReset\UnavailablePasswordResetCodeDelivery;
use App\Contexts\Platform\Application\Contracts\RegistrationVerificationCodeDelivery;
use App\Contexts\Platform\Infrastructure\Verification\UnavailableRegistrationVerificationCodeDelivery;
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
                    'api.v1.schools.promotion-rules.index',
                    'api.v1.schools.promotion-rules.store',
                    'api.v1.schools.promotion-rules.show',
                    'api.v1.schools.promotion-rules.update',
                    'api.v1.schools.promotion-rules.activate',
                    'api.v1.schools.promotion-rules.deactivate',
                    'api.v1.schools.academic-context',
                    'api.v1.schools.academic-readiness',
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
