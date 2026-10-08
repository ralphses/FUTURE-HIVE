<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'key_hash', 'fingerprint_hash', 'registration_id', 'first_request_id',
    'replay_count', 'last_replayed_at', 'status',
])]
#[Hidden(['id', 'key_hash', 'fingerprint_hash'])]
class IdempotencyRecord extends Model
{
    /** @return BelongsTo<SchoolRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(SchoolRegistration::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'replay_count' => 'integer',
            'last_replayed_at' => 'datetime',
        ];
    }
}
