<?php

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['students.audit.read', 'guardians.audit.read'] as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['name' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'active' => true],
            );

            Role::query()->whereIn('key', ['school_admin', 'proprietor', 'principal'])->get()->each(
                fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]),
            );
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['students.audit.read', 'guardians.audit.read'])->get();
        Role::query()->get()->each(fn (Role $role): mixed => $permissions->each(fn (Permission $permission): mixed => $role->permissions()->detach($permission->getKey())));
        $permissions->each->delete();
    }
};
