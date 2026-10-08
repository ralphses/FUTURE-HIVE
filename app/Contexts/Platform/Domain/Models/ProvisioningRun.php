<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Contexts\Platform\Domain\Enums\ProvisioningRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'registration_id', 'school_id', 'status', 'attempts', 'request_id', 'failure_code', 'failure_message'])]
#[Hidden(['id', 'failure_message'])]
final class ProvisioningRun extends Model
{
    /** @return BelongsTo<SchoolRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(SchoolRegistration::class, 'registration_id');
    }

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ProvisioningRunStatus::class,
            'attempts' => 'integer',
        ];
    }
}
