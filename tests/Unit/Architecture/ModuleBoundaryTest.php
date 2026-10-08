<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class ModuleBoundaryTest extends TestCase
{
    public function test_platform_health_slice_is_located_inside_the_context_structure(): void
    {
        self::assertFileExists(base_path('app/Contexts/Platform/Application/Actions/HealthCheckAction.php'));
        self::assertFileExists(base_path('app/Contexts/Platform/Http/Controllers/Api/V1/HealthController.php'));
        self::assertFileDoesNotExist(base_path('app/Http/Controllers/Api/V1/HealthController.php'));
    }

    public function test_platform_context_does_not_import_another_context(): void
    {
        $contextPath = base_path('app/Contexts/Platform');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($contextPath));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertDoesNotMatchRegularExpression(
                '/use\\s+App\\\\Contexts\\\\(?!Platform\\\\)[A-Za-z0-9_\\\\]+;/',
                $contents,
                $file->getPathname().' imports another context directly.',
            );
        }
    }
}
