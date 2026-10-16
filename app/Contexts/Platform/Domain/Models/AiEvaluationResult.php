<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'evaluation_run_id', 'dimension', 'status', 'score', 'issue_code', 'evidence_label'])]
#[Hidden(['id', 'evaluation_run_id'])]
final class AiEvaluationResult extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $result): void {
            $result->public_id ??= (string) Str::uuid7();
        });
    }
}
