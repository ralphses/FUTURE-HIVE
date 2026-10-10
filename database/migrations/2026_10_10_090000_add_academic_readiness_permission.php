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
        $definition = AuthorizationCatalogue::permissions()['academic.readiness.read'];
        $permission = Permission::query()->firstOrCreate(['key' => 'academic.readiness.read'], ['label' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'is_active' => true]);
        Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'academic.readiness.read')->first();
        if ($permission !== null) {
            Role::query()->whereIn('key', array_keys(AuthorizationCatalogue::roles()))->each(fn (Role $role) => $role->permissions()->detach($permission->getKey()));
            $permission->delete();
        }
    }
};
