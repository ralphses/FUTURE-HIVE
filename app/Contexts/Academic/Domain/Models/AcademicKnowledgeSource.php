<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'source_type', 'institution_reference', 'label', 'description', 'content', 'version', 'status', 'effective_at', 'expires_at', 'created_by', 'approved_by', 'approved_at', 'request_id'])]
#[Hidden(['id', 'created_by', 'approved_by', 'request_id'])]
final class AcademicKnowledgeSource extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $source): void {
            $source->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'version' => 'integer',
            'effective_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
