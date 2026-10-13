<?php

declare(strict_types=1);

namespace App\Contexts\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'preferences'])]
#[Hidden(['id', 'user_id'])]
final class UserNotificationPreference extends Model
{
    protected $table = 'user_notification_preferences';

    /** @return BelongsTo<UserIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserIdentity::class, 'user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }
}
