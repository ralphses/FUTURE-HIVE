<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AuditEvent extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'school_id',
        'actor_id',
        'action',
        'subject_type',
        'subject_public_id',
        'reason',
        'request_id',
        'authorization_context',
        'state_transition',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'authorization_context' => 'array',
            'state_transition' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            $event->public_id ??= (string) Str::uuid7();
        });
    }
}
