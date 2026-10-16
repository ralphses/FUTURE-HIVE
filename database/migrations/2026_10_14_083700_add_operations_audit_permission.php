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
        $key = 'operations.audit.read';
        $definition = AuthorizationCatalogue::permissions()[$key];
        Permission::query()->firstOrCreate(['key' => $key], [
            'label' => $definition['label'],
            'description' => $definition['description'],
            'scope' => 'school',
            'is_active' => true,
        ]);

        $permissionId = Permission::query()->where('key', $key)->value('id');
        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permissionId]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'operations.audit.read')->first();
        if (! $permission instanceof Permission) {
            return;
        }

        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->each(
            fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()),
        );
        $permission->delete();
    }
};
