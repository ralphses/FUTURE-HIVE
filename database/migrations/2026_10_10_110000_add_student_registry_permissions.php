<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = AuthorizationCatalogue::permissions();
        foreach (['students.read', 'students.admit', 'students.manage'] as $key) {
            $definition = $permissions[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }
        Permission::query()->where('key', 'students.read')->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
        Permission::query()->whereIn('key', ['students.admit', 'students.manage'])->get()->each(function (Permission $permission): void {
            Role::query()->whereIn('key', ['school_admin', 'proprietor'])->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['students.read', 'students.admit', 'students.manage'])->get();
        Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(fn (Role $role) => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('key', ['students.read', 'students.admit', 'students.manage'])->delete();
    }
};
