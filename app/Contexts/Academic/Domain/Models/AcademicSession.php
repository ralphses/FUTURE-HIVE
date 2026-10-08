<?php

declare(strict_types=1);

namespace App\Contexts\Academic\Domain\Models;

use App\Support\Tenancy\TenantScoped;
use Database\Factories\AcademicSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['school_id', 'name', 'code', 'start_date', 'end_date', 'status', 'activated_at', 'closed_at', 'public_id'])]
#[Hidden(['id'])]
final class AcademicSession extends Model
{
    /** @use HasFactory<AcademicSessionFactory> */
    use HasFactory;

    use TenantScoped;

    protected static function newFactory(): AcademicSessionFactory
    {
        return AcademicSessionFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(function (self $session): void {
            $session->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<AcademicTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }
}
