<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'display_name', 'metadata'])]
#[Hidden(['id', 'user_id', 'metadata'])]
final class GuardianProfile extends Model
{
    protected $table = 'guardian_profiles';

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return HasMany<StudentGuardianRelationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(StudentGuardianRelationship::class);
    }
}
