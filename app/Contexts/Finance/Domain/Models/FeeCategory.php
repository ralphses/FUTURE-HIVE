<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'code', 'name', 'description', 'status'])]
#[Hidden(['id', 'school_id'])]
final class FeeCategory extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $category): void {
            $category->public_id ??= (string) Str::uuid7();
        });
    }
}
