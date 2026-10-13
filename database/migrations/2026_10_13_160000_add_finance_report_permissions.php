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
        $keys = ['finance.reports.read', 'finance.reports.export'];
        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }
        foreach (['school_admin', 'proprietor', 'bursar'] as $roleKey) {
            Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id'));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['finance.reports.read', 'finance.reports.export'])->get();
        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'bursar'])->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('id', $permissions->modelKeys())->delete();
    }
};
