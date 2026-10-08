<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Observability\MetricsRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public const ATTRIBUTE = 'api_request_id';

    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Context::add('request_id', $requestId);
        $this->metrics->increment('http.requests.started');

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);
            $this->metrics->increment('http.requests.completed', [
                'status' => $response->getStatusCode(),
            ]);

            return $response;
        } finally {
            Context::forget('request_id');
        }
    }
}
