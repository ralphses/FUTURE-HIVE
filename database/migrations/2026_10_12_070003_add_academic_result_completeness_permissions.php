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
            'academic.results.completeness.read' => ['Read result completeness', 'Read safe term-scoped result completeness checks.', 'school'],
            'academic.results.completeness.manage' => ['Manage result completeness', 'Run server-controlled result completeness checks.', 'school'],
        ];

        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            $roles = str_ends_with($key, '.read') ? ['school_admin', 'teacher', 'proprietor', 'principal', 'hod_reviewer'] : ['school_admin', 'proprietor', 'principal', 'hod_reviewer'];
            foreach ($roles as $roleKey) {
                Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.completeness.read', 'academic.results.completeness.manage'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
