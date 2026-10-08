<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/v1/testing/validation', function (Request $request) {
            $request->validate([
                'name' => ['required', 'string'],
            ]);

            return ApiResponse::data(['valid' => true]);
        });

        Route::get('/api/v1/testing/failure', function (): never {
            throw new RuntimeException('sensitive internal detail');
        });

        Route::get('/api/v1/testing/unauthenticated', function (): never {
            throw new AuthenticationException;
        });

        Route::get('/api/v1/testing/forbidden', function (): never {
            throw new AuthorizationException;
        });

        Route::get('/api/v1/testing/model-not-found', function (): never {
            throw new ModelNotFoundException;
        });

        Route::get('/api/v1/testing/paginated', function (Request $request) {
            $perPage = ApiResponse::perPage($request->integer('per_page'));
            $paginator = new LengthAwarePaginator(
                [['id' => 1]],
                3,
                $perPage,
                1,
                ['path' => $request->url()],
            );

            return ApiResponse::paginated($paginator);
        });
    }

    public function test_health_endpoint_returns_the_versioned_data_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertHeader('X-Request-ID');
    }

    public function test_unknown_api_routes_return_the_standard_not_found_error(): void
    {
        $response = $this->getJson('/api/v1/missing');

        $response
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertJsonPath('error.details', [])
            ->assertJsonStructure(['error' => ['request_id']])
            ->assertHeader('X-Request-ID');

        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $response->json('error.request_id'),
        );
    }

    public function test_unsupported_api_methods_return_the_standard_method_error(): void
    {
        $response = $this->postJson('/api/v1/health');

        $response
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED')
            ->assertJsonStructure(['error' => ['message', 'details', 'request_id']]);
    }

    public function test_validation_failures_return_field_keyed_details(): void
    {
        $response = $this->postJson('/api/v1/testing/validation', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.name.0', 'The name field is required.')
            ->assertJsonStructure(['error' => ['message', 'request_id']]);
    }

    public function test_authentication_failures_return_the_standard_unauthenticated_error(): void
    {
        $this->getJson('/api/v1/testing/unauthenticated')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['message', 'details', 'request_id']]);
    }

    public function test_authorization_failures_return_the_standard_forbidden_error(): void
    {
        $this->getJson('/api/v1/testing/forbidden')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonStructure(['error' => ['message', 'details', 'request_id']]);
    }

    public function test_model_not_found_failures_return_the_standard_not_found_error(): void
    {
        $this->getJson('/api/v1/testing/model-not-found')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
            ->assertJsonStructure(['error' => ['message', 'details', 'request_id']]);
    }

    public function test_unhandled_api_failures_are_generic_and_do_not_leak_details(): void
    {
        $response = $this->getJson('/api/v1/testing/failure');

        $response
            ->assertServerError()
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('error.message', 'An unexpected error occurred.')
            ->assertDontSee('sensitive internal detail')
            ->assertJsonStructure(['error' => ['details', 'request_id']]);
    }

    public function test_paginated_responses_use_the_documented_shape_and_page_boundaries(): void
    {
        $response = $this->getJson('/api/v1/testing/paginated?per_page=1000');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', ApiResponse::MAX_PAGE_SIZE)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
    }
}
