<?php

namespace App\Providers;

use App\Contexts\Identity\Application\Contracts\ContactVerificationCodeDelivery;
use App\Contexts\Identity\Application\Contracts\PasswordResetCodeDelivery;
use App\Contexts\Identity\Infrastructure\ContactVerification\UnavailableContactVerificationCodeDelivery;
use App\Contexts\Identity\Infrastructure\PasswordReset\UnavailablePasswordResetCodeDelivery;
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
        $this->app->singleton(MetricsRecorder::class, StructuredLogMetricsRecorder::class);
        $this->app->singleton(PhoneNumberUtil::class, static fn (): PhoneNumberUtil => PhoneNumberUtil::getInstance());
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
                    'api.v1.auth.password.forgot',
                    'api.v1.auth.password.reset',
                    'api.v1.auth.password.change',
                    'api.v1.auth.verification.request',
                    'api.v1.auth.verification.confirm',
                    'api.v1.me.memberships',
                    'api.v1.schools.invitations.create',
                    'api.v1.invitations.accept',
                    'api.v1.invitations.revoke',
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
