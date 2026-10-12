<?php

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'academic.results.statistics.read' => ['Read ranking and statistics policies', 'Read school-scoped ranking and statistics policy configuration.', 'school'],
            'academic.results.statistics.manage' => ['Manage ranking and statistics policies', 'Create and lifecycle-manage school-scoped ranking and statistics policy versions.', 'school'],
        ];
        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            $roles = str_ends_with($key, '.read') ? ['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'] : ['school_admin', 'proprietor', 'principal'];
            foreach ($roles as $roleKey) {
                Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.statistics.read', 'academic.results.statistics.manage'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
