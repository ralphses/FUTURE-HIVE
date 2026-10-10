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
        $permissions = AuthorizationCatalogue::permissions();
        foreach (['academic.assessment-policies.read', 'academic.assessment-policies.manage'] as $key) {
            $permission = $permissions[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['public_id' => (string) Str::uuid7(), 'label' => $permission['label'], 'description' => $permission['description'], 'scope' => 'school', 'is_active' => true]);
        }

        $this->syncRolePermissions();
    }

    public function down(): void
    {
        $ids = Permission::query()->whereIn('key', ['academic.assessment-policies.read', 'academic.assessment-policies.manage'])->pluck('id');
        Role::query()->each(function (Role $role) use ($ids): void {
            $role->permissions()->detach($ids);
        });
        Permission::query()->whereIn('id', $ids)->delete();
    }

    private function syncRolePermissions(): void
    {
        foreach (AuthorizationCatalogue::rolePermissions() as $roleKey => $permissionKeys) {
            $role = Role::query()->where('key', $roleKey)->first();
            if (! $role) {
                continue;
            }
            foreach ($permissionKeys as $permissionKey) {
                $permission = Permission::query()->where('key', $permissionKey)->first();
                if ($permission) {
                    $role->permissions()->syncWithoutDetaching([$permission->id]);
                }
            }
        }
    }
};
