<?php

namespace App\Providers;

use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\CloudinarySdkClient;
use App\Support\Files\MalwareScanner;
use App\Support\Files\UnavailableMalwareScanner;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\StructuredLogMetricsRecorder;
use App\Support\OpenApi\FoundationContractDocumentTransformer;
use Cloudinary\Api\Upload\UploadApi;
use Dedoc\Scramble\Scramble;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
        $this->app->singleton(MetricsRecorder::class, StructuredLogMetricsRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (class_exists(Scramble::class)) {
            Scramble::configure()->routes(static fn (Route $route): bool => in_array(
                $route->getName(),
                [
                    'api.v1.health',
                    'api.v1.health.readiness',
                    'api.v1.files.download',
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
