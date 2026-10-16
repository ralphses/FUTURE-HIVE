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
        $key = 'operations.proprietor-dashboard.read';
        $definition = AuthorizationCatalogue::permissions()[$key];
        $permission = Permission::query()->firstOrCreate(['key' => $key], [
            'label' => $definition['label'],
            'description' => $definition['description'],
            'scope' => 'school',
            'is_active' => true,
        ]);

        Role::query()->where('key', 'proprietor')->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'operations.proprietor-dashboard.read')->first();
        if ($permission instanceof Permission) {
            Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
            $permission->delete();
        }
    }
};
