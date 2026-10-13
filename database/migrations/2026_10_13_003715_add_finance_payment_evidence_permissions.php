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
        $keys = ['finance.payment-evidence.read', 'finance.payment-evidence.manage', 'finance.payment-evidence.review'];
        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }
        foreach (['school_admin', 'proprietor', 'bursar', 'principal'] as $roleKey) {
            Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id'));
        }
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['finance.payment-evidence.read', 'finance.payment-evidence.manage', 'finance.payment-evidence.review'])->get();
        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'bursar', 'principal'])->each(fn (Role $role): mixed => $role->permissions()->detach($permissions->modelKeys()));
        Permission::query()->whereIn('id', $permissions->modelKeys())->delete();
    }
};
