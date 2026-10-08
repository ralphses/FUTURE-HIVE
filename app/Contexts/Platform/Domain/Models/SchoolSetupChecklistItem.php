<?php

declare(strict_types=1);

namespace App\Contexts\Platform\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['school_id', 'item_key', 'status', 'completed_at'])]
final class SchoolSetupChecklistItem extends Model
{
    use TenantScoped;

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
