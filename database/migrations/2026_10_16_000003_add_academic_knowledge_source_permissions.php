<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'academic.knowledge-sources.read' => ['Read academic knowledge sources', 'Read safe versioned JAMB and institution requirement sources.'],
            'academic.knowledge-sources.manage' => ['Manage academic knowledge sources', 'Manage governed academic knowledge-source versions.'],
        ];
        $ids = [];
        foreach ($permissions as $key => [$label, $description]) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['public_id' => (string) Str::uuid7(), 'label' => $label, 'description' => $description, 'scope' => 'platform', 'is_active' => true],
            );
            $ids[] = $permission->getKey();
        }
        Role::query()->whereIn('key', ['platform_admin', 'platform_ops'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching($ids),
        );
    }

    public function down(): void
    {
        $permissions = Permission::query()->whereIn('key', ['academic.knowledge-sources.read', 'academic.knowledge-sources.manage'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
