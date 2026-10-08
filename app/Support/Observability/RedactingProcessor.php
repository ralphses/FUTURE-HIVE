<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;
        $requestId = Context::get('request_id');

        if (is_string($requestId) && $requestId !== '') {
            $extra['request_id'] = $requestId;
        }

        return $record->with(
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($extra),
        );
    }
}
