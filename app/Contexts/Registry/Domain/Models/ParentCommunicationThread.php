<?php

declare(strict_types=1);

namespace App\Contexts\Registry\Domain\Models;

use App\Contexts\Identity\Domain\Models\School;
use App\Contexts\Identity\Domain\Models\UserIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'school_id', 'student_id', 'guardian_profile_id', 'title', 'status', 'created_by', 'closed_by', 'closed_at'])]
#[Hidden(['id', 'school_id', 'student_id', 'guardian_profile_id', 'created_by', 'closed_by'])]
final class ParentCommunicationThread extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $thread): void {
            $thread->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<GuardianProfile, $this> */
    public function guardianProfile(): BelongsTo
    {
        return $this->belongsTo(GuardianProfile::class);
    }

    /** @return BelongsTo<UserIdentity, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'created_by');
    }

    /** @return HasMany<ParentCommunicationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ParentCommunicationMessage::class, 'thread_id');
    }
}
