<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

final class VerifyOpenApiContract extends Command
{
    protected $signature = 'openapi:check {--artifact=openapi/openapi.json : The committed OpenAPI artifact path}';

    protected $description = 'Verifies the generated OpenAPI document and implemented API route coverage.';

    public function handle(): int
    {
        $artifactPath = base_path((string) $this->option('artifact'));

        if (! File::exists($artifactPath)) {
            $this->error("OpenAPI artifact does not exist: {$artifactPath}");

            return self::FAILURE;
        }

        try {
            $artifact = $this->decode(File::get($artifactPath));
        } catch (JsonException $exception) {
            $this->error('OpenAPI artifact is not valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (($artifact['openapi'] ?? null) !== '3.1.0') {
            $this->error('OpenAPI artifact must declare OpenAPI version 3.1.0.');

            return self::FAILURE;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'schoolos-openapi-');

        if ($temporaryPath === false) {
            $this->error('Unable to create a temporary OpenAPI artifact.');

            return self::FAILURE;
        }

        try {
            $exitCode = Artisan::call('scramble:export', [
                '--path' => $temporaryPath,
                '--fail-on-unknown' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error('Scramble could not generate a valid OpenAPI document.');

                return self::FAILURE;
            }

            $generated = $this->decode(File::get($temporaryPath));

            if ($this->canonical($artifact) !== $this->canonical($generated)) {
                $this->error('The committed OpenAPI artifact differs from Scramble output. Run php artisan scramble:export --path='.$this->option('artifact'));

                return self::FAILURE;
            }

            if (! $this->routesAreDocumented($generated)) {
                return self::FAILURE;
            }
        } catch (JsonException $exception) {
            $this->error('Generated OpenAPI output is not valid JSON: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::delete($temporaryPath);
        }

        $this->info('OpenAPI artifact, generated output and API route coverage are consistent.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('The document root must be an object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function canonical(array $document): string
    {
        $this->sortRecursively($document);

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function sortRecursively(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function routesAreDocumented(array $document): bool
    {
        $paths = $document['paths'] ?? [];
        $implemented = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || ! Str::startsWith($name, 'api.v1.')) {
                continue;
            }

            $uri = '/'.Str::after($route->uri(), 'api/v1/');
            $uri = $uri === '/api/v1' ? '/' : $uri;

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $implemented[$uri][strtolower($method)] = true;
            }
        }

        $documented = [];

        foreach ($paths as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[$path][strtolower($method)] = true;
            }
        }

        $this->sortRecursively($implemented);
        $this->sortRecursively($documented);

        if ($implemented !== $documented) {
            $this->error('Implemented API routes and documented OpenAPI paths differ.');
            $this->line('Implemented: '.json_encode($implemented, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->line('Documented: '.json_encode($documented, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return false;
        }

        return true;
    }
}
