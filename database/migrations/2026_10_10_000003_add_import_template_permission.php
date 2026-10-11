<?php

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $key = 'imports.templates.read';
        $definition = AuthorizationCatalogue::permissions()[$key];
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true],
        );

        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'principal'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'imports.templates.read')->first();
        if ($permission === null) {
            return;
        }

        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
        $permission->delete();
    }
};
