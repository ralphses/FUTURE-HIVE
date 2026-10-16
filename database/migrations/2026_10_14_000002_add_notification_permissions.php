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
        foreach (AuthorizationCatalogue::permissions() as $key => $definition) {
            if (! str_starts_with($key, 'operations.notification')) {
                continue;
            }

            Permission::query()->firstOrCreate(['key' => $key], [...$definition, 'is_active' => true]);
        }

        $permissions = Permission::query()->whereIn('key', [
            'operations.notification-templates.read', 'operations.notification-templates.manage',
            'operations.notifications.read', 'operations.notifications.manage', 'operations.notifications.send',
        ])->pluck('id');

        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->each(function (Role $role) use ($permissions): void {
            $role->permissions()->syncWithoutDetaching($permissions->all());
        });
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', [
            'operations.notification-templates.read', 'operations.notification-templates.manage',
            'operations.notifications.read', 'operations.notifications.manage', 'operations.notifications.send',
        ])->pluck('id');
        Role::query()->each(fn (Role $role) => $role->permissions()->detach($permissions->all()));
        Permission::query()->whereIn('id', $permissions)->delete();
    }
};
