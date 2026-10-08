<?php

use App\Contexts\Platform\Http\Controllers\Api\V1\HealthController;
use App\Support\Files\CloudinaryFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.v1.health');
Route::get('/files/download', CloudinaryFileDownloadController::class)
    ->middleware('signed')
    ->name('api.v1.files.download');
