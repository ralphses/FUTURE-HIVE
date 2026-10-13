<?php

declare(strict_types=1);

namespace App\Contexts\Finance\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'code', 'name', 'description', 'adjustment_type', 'calculation_method', 'fixed_amount_minor_units', 'percentage_basis_points', 'allows_multiple', 'eligibility', 'status', 'created_by', 'activated_by', 'activated_at', 'retired_by', 'retired_at'])]
#[Hidden(['id', 'school_id', 'created_by', 'activated_by', 'retired_by'])]
final class FeeAdjustmentProgram extends Model
{
    use TenantScoped;

    protected static function booted(): void
    {
        self::creating(function (self $program): void {
            $program->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['fixed_amount_minor_units' => 'integer', 'percentage_basis_points' => 'integer', 'allows_multiple' => 'boolean', 'eligibility' => 'array', 'activated_at' => 'datetime', 'retired_at' => 'datetime'];
    }
}
