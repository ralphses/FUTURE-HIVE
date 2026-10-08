<?php

use App\Http\Middleware\RequestId;
use App\Support\Observability\MetricsRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule
            ->command('queue:prune-failed', [
                '--hours' => (int) config('queue.failed_prune_hours', 168),
            ])
            ->daily()
            ->onOneServer()
            ->withoutOverlapping(30);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $requestId = (string) $request->attributes->get(
                RequestId::ATTRIBUTE,
                (string) Str::uuid(),
            );

            $status = 500;
            $code = 'INTERNAL_ERROR';
            $message = 'An unexpected error occurred.';
            $details = [];

            if ($exception instanceof ValidationException) {
                $status = 422;
                $code = 'VALIDATION_FAILED';
                $message = 'The given data was invalid.';
                $details = $exception->errors();
            } elseif ($exception instanceof AuthenticationException) {
                $status = 401;
                $code = 'UNAUTHENTICATED';
                $message = 'Authentication is required.';
            } elseif ($exception instanceof AuthorizationException) {
                $status = 403;
                $code = 'FORBIDDEN';
                $message = 'You are not authorized to perform this action.';
            } elseif ($exception instanceof ModelNotFoundException) {
                $status = 404;
                $code = 'RESOURCE_NOT_FOUND';
                $message = 'The requested resource was not found.';
            } elseif ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $code = match ($status) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'RESOURCE_NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    default => 'HTTP_ERROR',
                };
                $message = match ($status) {
                    401 => 'Authentication is required.',
                    403 => 'You are not authorized to perform this action.',
                    404 => 'The requested resource was not found.',
                    405 => 'The requested method is not allowed.',
                    default => 'The request could not be completed.',
                };
            }

            return response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                    'request_id' => $requestId,
                ],
            ], $status, [
                'X-Request-ID' => $requestId,
            ]);
        });

        $exceptions->report(function (Throwable $exception): void {
            app(MetricsRecorder::class)->increment('exceptions.reported', [
                'type' => class_basename($exception),
            ]);
        });
    })->create();
