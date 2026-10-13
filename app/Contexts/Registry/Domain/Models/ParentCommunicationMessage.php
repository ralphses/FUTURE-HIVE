<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'thread_id', 'author_id', 'body', 'idempotency_key_hash', 'request_fingerprint'])]
#[Hidden(['id', 'school_id', 'thread_id', 'author_id', 'idempotency_key_hash', 'request_fingerprint'])]
final class ParentCommunicationMessage extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $message): void {
            $message->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<ParentCommunicationThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ParentCommunicationThread::class, 'thread_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'author_id');
    }
}
