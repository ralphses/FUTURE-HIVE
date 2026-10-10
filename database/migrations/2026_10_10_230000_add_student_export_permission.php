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
        $definition = AuthorizationCatalogue::permissions()['students.export'];
        $permission = Permission::query()->firstOrCreate(
            ['key' => 'students.export'],
            ['name' => $definition['label'], 'description' => $definition['description'], 'scope' => 'school', 'active' => true],
        );

        Role::query()->whereIn('key', ['school_admin', 'principal', 'proprietor'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'students.export')->first();
        if ($permission === null) {
            return;
        }

        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
        $permission->delete();
    }
};
