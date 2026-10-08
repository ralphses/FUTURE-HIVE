<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contexts\Platform\Application\Actions\RecordAuditEventAction;
use App\Contexts\Platform\Application\DTOs\AuditEventData;
use App\Support\Observability\MetricsRecorder;
use App\Support\Observability\RedactingProcessor;
use App\Support\Observability\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Monolog\LogRecord;
use ReflectionClass;
use Tests\TestCase;

final class ObservabilityAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeMetricsRecorder $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = new FakeMetricsRecorder;
        $this->app->instance(MetricsRecorder::class, $this->metrics);
    }

    public function test_request_id_is_propagated_to_context_and_metrics_are_recorded(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()->assertHeader('X-Request-ID');
        self::assertContains('http.requests.started', $this->metrics->increments);
        self::assertContains('http.requests.completed', $this->metrics->increments);
    }

    public function test_structured_context_redacts_secrets_and_sensitive_personal_fields(): void
    {
        Context::add('request_id', 'request-fictional-001');

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'structured',
            level: Level::Info,
            message: 'audit event',
            context: [
                'email' => 'person@example.test',
                'nested' => ['token' => 'secret-token'],
                'safe' => 'fictional-value',
            ],
        );

        $processed = (new RedactingProcessor(new SensitiveDataRedactor))($record);
        Context::forget('request_id');

        self::assertSame(SensitiveDataRedactor::REDACTED, $processed->context['email']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $processed->context['nested']['token']);
        self::assertSame('fictional-value', $processed->context['safe']);
        self::assertSame('request-fictional-001', $processed->extra['request_id']);
    }

    public function test_audit_events_persist_uuidv7_public_ids_and_redacted_metadata(): void
    {
        $event = (new RecordAuditEventAction(new SensitiveDataRedactor))->execute(
            new AuditEventData(
                action: 'fixture.created',
                subjectType: 'Fixture',
                subjectPublicId: '0199fict-0000-7000-8000-000000000001',
                schoolId: 101,
                actorId: 202,
                requestId: '0199f1c0-0000-7000-8000-000000000002',
                authorizationContext: ['role' => 'platform_operator'],
                stateTransition: ['from' => 'draft', 'to' => 'active'],
                metadata: ['token' => 'do-not-persist', 'safe' => 'fictional'],
                requiresSchoolContext: true,
            ),
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $event->public_id,
        );
        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'school_id' => 101,
            'actor_id' => 202,
            'action' => 'fixture.created',
        ]);
        $metadata = $event->getAttribute('metadata');
        self::assertIsArray($metadata);
        self::assertSame(SensitiveDataRedactor::REDACTED, $metadata['token']);
        self::assertSame('fictional', $metadata['safe']);
        self::assertFalse((new ReflectionClass(RecordAuditEventAction::class))->hasMethod('update'));
        self::assertFalse((new ReflectionClass(RecordAuditEventAction::class))->hasMethod('delete'));
    }

    public function test_school_owned_audit_events_require_trusted_school_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RecordAuditEventAction(new SensitiveDataRedactor))->execute(
            new AuditEventData(
                action: 'fixture.created',
                subjectType: 'Fixture',
                requiresSchoolContext: true,
            ),
        );
    }

    public function test_platform_audit_events_can_be_recorded_without_school_context(): void
    {
        $event = (new RecordAuditEventAction(new SensitiveDataRedactor))->execute(
            new AuditEventData(
                action: 'platform.started',
                subjectType: 'Platform',
            ),
        );

        self::assertNull($event->school_id);
        self::assertSame('platform.started', $event->action);
    }
}

final class FakeMetricsRecorder implements MetricsRecorder
{
    /** @var list<string> */
    public array $increments = [];

    public function increment(string $name, array $tags = []): void
    {
        $this->increments[] = $name;
    }

    public function timing(string $name, int $milliseconds, array $tags = []): void {}
}
