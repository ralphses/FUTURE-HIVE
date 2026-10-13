<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $keys = ['finance.fee-plans.read', 'finance.fee-plans.manage'];
        foreach ($keys as $key) {
            $definition = AuthorizationCatalogue::permissions()[$key];
            Permission::query()->firstOrCreate(['key' => $key], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        }

        foreach (['school_admin', 'proprietor', 'bursar'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id'));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['finance.fee-plans.read', 'finance.fee-plans.manage'])->get();
        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'bursar'])->each(function (Role $role) use ($permissions): void {
            $role->permissions()->detach($permissions->modelKeys());
        });
        Permission::query()->whereIn('key', ['finance.fee-plans.read', 'finance.fee-plans.manage'])->delete();
    }
};
