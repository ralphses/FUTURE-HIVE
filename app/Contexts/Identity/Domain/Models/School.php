<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'status', 'public_id'])]
#[Hidden(['id'])]
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected static function newFactory(): SchoolFactory
    {
        return SchoolFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $school): void {
            $school->public_id ??= (string) Str::uuid7();
        });

        static::created(function (self $school): void {
            DB::table('school_profiles')->insert([
                'school_id' => $school->getKey(),
                'country' => 'NG',
                'timezone' => 'Africa/Lagos',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** @return HasMany<SchoolMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(SchoolMembership::class);
    }

    /** @return HasMany<SchoolInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(SchoolInvitation::class);
    }
}
