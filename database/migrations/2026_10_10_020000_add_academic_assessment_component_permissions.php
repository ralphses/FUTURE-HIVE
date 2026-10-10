<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $keys = ['academic.assessment-components.read', 'academic.assessment-components.manage'];

        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], [
                'public_id' => (string) Str::uuid7(),
                'label' => $definition['label'],
                'description' => $definition['description'],
                'scope' => 'school',
                'is_active' => true,
            ]);
        }

        foreach (AuthorizationCatalogue::rolePermissions() as $roleKey => $permissionKeys) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role) {
                continue;
            }

            foreach (array_intersect($permissionKeys, $keys) as $permissionKey) {
                $permission = Permission::query()->where('key', $permissionKey)->first();
                if ($permission) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }
    }

    public function down(): void
    {
        $keys = ['academic.assessment-components.read', 'academic.assessment-components.manage'];
        $permissionIds = Permission::query()->whereIn('key', $keys)->pluck('id');
        Role::query()->each(function (Role $role) use ($permissionIds): void {
            $role->permissions()->detach($permissionIds);
        });
        Permission::query()->whereIn('key', $keys)->delete();
    }
};
