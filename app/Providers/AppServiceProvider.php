<?php

namespace App\Providers;

use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\CloudinarySdkClient;
use App\Support\Files\MalwareScanner;
use App\Support\Files\UnavailableMalwareScanner;
use Cloudinary\Api\Upload\UploadApi;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
