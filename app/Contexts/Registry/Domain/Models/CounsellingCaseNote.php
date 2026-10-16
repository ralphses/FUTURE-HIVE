<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'counselling_case_id', 'author_id', 'note_type', 'content', 'request_id'])]
#[Hidden(['id', 'school_id', 'counselling_case_id', 'author_id', 'request_id'])]
final class CounsellingCaseNote extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $note): void {
            $note->public_id ??= (string) Str::uuid7();
        });
    }

    /** @return BelongsTo<CounsellingCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CounsellingCase::class, 'counselling_case_id');
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'author_id');
    }

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'request_id' => 'string'];
    }
}
