<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'evaluation_run_id', 'decision', 'reason', 'reviewer_id', 'request_id'])]
#[Hidden(['id', 'evaluation_run_id', 'reviewer_id', 'request_id'])]
final class AiEvaluationReview extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $review): void {
            $review->public_id ??= (string) Str::uuid7();
        });
    }
}
