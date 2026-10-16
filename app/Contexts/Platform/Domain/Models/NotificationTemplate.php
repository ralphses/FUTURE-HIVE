<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'template_key', 'channel', 'version', 'title', 'body', 'status', 'created_by', 'updated_by', 'activated_at', 'retired_at', 'request_id'])]
#[Hidden(['id', 'school_id', 'created_by', 'updated_by', 'request_id'])]
final class NotificationTemplate extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $template): void {
            $template->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }
}
