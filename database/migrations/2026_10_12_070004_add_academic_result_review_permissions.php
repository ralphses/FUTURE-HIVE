<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'academic.results.review.read' => ['Read result review queue', 'View safe result runs awaiting academic leadership review.', 'school'],
            'academic.results.review.manage' => ['Manage result review queue', 'Use server-controlled result review actions when enabled.', 'school'],
        ];

        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer'] as $roleKey) {
                Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.review.read', 'academic.results.review.manage'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
