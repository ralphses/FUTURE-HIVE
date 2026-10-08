<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Files\CloudinaryAssetClient;
use App\Support\Files\CloudinaryAssetStore;
use App\Support\Files\FileScanResult;
use App\Support\Files\MalwareScanner;
use App\Support\Files\SchoolFileReference;
use App\Support\Files\UnsafeFileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FileStorageTest extends TestCase
{
    private FakeCloudinaryClient $client;

    private FakeMalwareScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.cloudinary.download_ttl' => 300,
            'services.cloudinary.upload_prefix' => 'schoolos/schools',
        ]);

        $this->client = new FakeCloudinaryClient;
        $this->scanner = new FakeMalwareScanner;

        $this->app->instance(CloudinaryAssetClient::class, $this->client);
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    public function test_cloudinary_configuration_does_not_expose_credentials(): void
    {
        self::assertArrayHasKey('url', config('services.cloudinary'));
        self::assertEmpty(config('services.cloudinary.url'));
        self::assertArrayNotHasKey('api_secret', config('services.cloudinary'));
    }

    public function test_clean_file_is_uploaded_as_an_authenticated_raw_school_asset(): void
    {
        $schoolId = (string) Str::uuid7();
        $file = UploadedFile::fake()->create('fictional-student-record.pdf', 10, 'application/pdf');
        $store = $this->app->make(CloudinaryAssetStore::class);

        $reference = $store->store($file, $schoolId);

        self::assertSame(FileScanResult::Clean, $this->scanner->lastResult);
        self::assertSame('raw', $this->client->lastOptions['resource_type']);
        self::assertSame('authenticated', $this->client->lastOptions['type']);
        self::assertStringStartsWith('schoolos/schools/'.$schoolId.'/', $this->client->lastOptions['public_id']);
        self::assertStringNotContainsString('fictional-student-record', $this->client->lastOptions['public_id']);
        self::assertSame($this->client->response['public_id'], $reference->publicId);
    }

    public function test_non_clean_scan_results_are_rejected_before_cloudinary_upload(): void
    {
        $this->scanner->result = FileScanResult::Infected;
        $store = $this->app->make(CloudinaryAssetStore::class);

        $this->expectException(UnsafeFileUpload::class);

        $store->store(
            UploadedFile::fake()->create('fictional-infected.pdf', 10, 'application/pdf'),
            (string) Str::uuid7(),
        );

        self::assertSame(0, $this->client->uploadCount);
    }

    public function test_unavailable_scan_results_are_rejected_before_cloudinary_upload(): void
    {
        $this->scanner->result = FileScanResult::Unavailable;
        $store = $this->app->make(CloudinaryAssetStore::class);

        try {
            $store->store(
                UploadedFile::fake()->create('fictional-unscanned.pdf', 10, 'application/pdf'),
                (string) Str::uuid7(),
            );
            self::fail('Unavailable scanner result was accepted.');
        } catch (UnsafeFileUpload) {
            self::assertSame(0, $this->client->uploadCount);
        }
    }

    public function test_scanner_errors_are_rejected_before_cloudinary_upload(): void
    {
        $this->scanner->throws = true;
        $store = $this->app->make(CloudinaryAssetStore::class);

        try {
            $store->store(
                UploadedFile::fake()->create('fictional-scanner-error.pdf', 10, 'application/pdf'),
                (string) Str::uuid7(),
            );
            self::fail('Scanner error was accepted.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->client->uploadCount);
        }
    }

    public function test_school_file_references_reject_invalid_and_cross_school_paths(): void
    {
        $schoolId = (string) Str::uuid7();

        $invalidReferences = [
            ['', 'schoolos/schools/'.$schoolId.'/asset', 'pdf'],
            [$schoolId, '../asset', 'pdf'],
            [$schoolId, '/absolute/asset', 'pdf'],
            [$schoolId, 'schoolos/schools/'.(string) Str::uuid7().'/asset', 'pdf'],
        ];

        foreach ($invalidReferences as [$referenceSchoolId, $publicId, $format]) {
            try {
                new SchoolFileReference($referenceSchoolId, $publicId, $format);
                self::fail('Invalid school file reference was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_signed_download_uses_application_and_cloudinary_signatures(): void
    {
        $store = $this->app->make(CloudinaryAssetStore::class);
        $schoolId = (string) Str::uuid7();
        $reference = new SchoolFileReference(
            $schoolId,
            'schoolos/schools/'.$schoolId.'/asset',
            'pdf',
        );

        $url = $store->temporaryDownloadUrl($reference);

        self::assertStringContainsString('signature=', $url);
        self::assertStringContainsString('expires=', $url);
        self::assertStringNotContainsString('secure_url', $url);
    }

    public function test_signed_download_route_rejects_unsigned_expired_tampered_and_cross_school_requests(): void
    {
        $schoolId = (string) Str::uuid7();
        $publicId = 'schoolos/schools/'.$schoolId.'/asset';
        $parameters = [
            'school_id' => $schoolId,
            'public_id' => $publicId,
            'format' => 'pdf',
        ];

        $signedUrl = URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addMinutes(5),
            $parameters,
        );

        $this->get('/api/v1/files/download?'.http_build_query($parameters))
            ->assertForbidden();

        $this->get($signedUrl.'&public_id='.urlencode($publicId.'-tampered'))
            ->assertForbidden();

        $this->get(URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->subMinute(),
            $parameters,
        ))->assertForbidden();

        $otherSchoolId = (string) Str::uuid7();
        $crossSchoolUrl = URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addMinutes(5),
            [
                'school_id' => $otherSchoolId,
                'public_id' => $publicId,
                'format' => 'pdf',
            ],
        );

        $this->get($crossSchoolUrl)->assertNotFound();
    }

    public function test_valid_signed_route_redirects_to_authenticated_cloudinary_download(): void
    {
        $schoolId = (string) Str::uuid7();
        $publicId = 'schoolos/schools/'.$schoolId.'/asset';
        $url = URL::temporarySignedRoute(
            'api.v1.files.download',
            now()->addMinutes(5),
            [
                'school_id' => $schoolId,
                'public_id' => $publicId,
                'format' => 'pdf',
            ],
        );

        $this->get($url)
            ->assertRedirect($this->client->downloadUrl);

        self::assertSame('authenticated', $this->client->lastDownloadOptions['type']);
        self::assertSame('raw', $this->client->lastDownloadOptions['resource_type']);
        self::assertTrue($this->client->lastDownloadOptions['attachment']);
    }
}

final class FakeCloudinaryClient implements CloudinaryAssetClient
{
    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /** @var array<string, mixed> */
    public array $lastDownloadOptions = [];

    /** @var array<string, mixed> */
    public array $response = [
        'public_id' => 'schoolos/schools/00000000-0000-7000-8000-000000000000/asset',
        'resource_type' => 'raw',
        'format' => 'pdf',
    ];

    public string $downloadUrl = 'https://res.cloudinary.test/raw/authenticated/signed-download';

    public int $uploadCount = 0;

    public function upload(UploadedFile $file, array $options): array
    {
        $this->uploadCount++;
        $this->lastOptions = $options;
        $this->response['public_id'] = $options['public_id'];

        return $this->response;
    }

    public function privateDownloadUrl(string $publicId, string $format, array $options): string
    {
        $this->lastDownloadOptions = $options;

        return $this->downloadUrl;
    }
}

final class FakeMalwareScanner implements MalwareScanner
{
    public FileScanResult $result = FileScanResult::Clean;

    public FileScanResult $lastResult = FileScanResult::Unavailable;

    public bool $throws = false;

    public function scan(UploadedFile $file): FileScanResult
    {
        if ($this->throws) {
            throw new \RuntimeException('fictional scanner failure');
        }

        $this->lastResult = $this->result;

        return $this->result;
    }
}
