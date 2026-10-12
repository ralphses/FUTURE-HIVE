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
            'academic.results.snapshots.read' => ['Read result snapshots', 'Read immutable school-scoped published result snapshots.', 'school'],
            'academic.results.snapshots.create' => ['Create result snapshots', 'Create an immutable snapshot from a published result run.', 'school'],
        ];

        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            $roles = str_ends_with($key, '.read') ? ['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] : ['proprietor', 'principal'];
            foreach ($roles as $roleKey) {
                Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.snapshots.read', 'academic.results.snapshots.create'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
