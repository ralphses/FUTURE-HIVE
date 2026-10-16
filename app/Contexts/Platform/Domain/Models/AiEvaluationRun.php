<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'feature_key', 'fixture_set', 'fixture_version', 'state', 'review_state', 'release_gate_status', 'provider_status', 'issue_codes', 'metrics', 'requested_by', 'reviewed_by', 'idempotency_key_hash', 'request_fingerprint', 'request_id', 'started_at', 'completed_at', 'reviewed_at'])]
#[Hidden(['id', 'requested_by', 'reviewed_by', 'idempotency_key_hash', 'request_fingerprint', 'request_id'])]
final class AiEvaluationRun extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'issue_codes' => 'array',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
