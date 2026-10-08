<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiResponse
{
    public const DEFAULT_PAGE_SIZE = 25;

    public const MAX_PAGE_SIZE = 100;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function data(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = 500,
        ?string $requestId = null,
        array $headers = [],
    ): JsonResponse {
        if ($requestId !== null) {
            $headers['X-Request-ID'] = $requestId;
        }

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $requestId,
            ],
        ], $status, $headers);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  array<string, string>  $headers
     */
    public static function paginated(LengthAwarePaginator $paginator, array $headers = []): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ], 200, $headers);
    }

    public static function perPage(?int $requested): int
    {
        return min(
            max($requested ?? self::DEFAULT_PAGE_SIZE, 1),
            self::MAX_PAGE_SIZE,
        );
    }
}
