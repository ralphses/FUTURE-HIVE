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
            'platform.ai-evaluations.read' => ['Read AI evaluation runs', 'Read safe AI evaluation status and rubric projections.'],
            'platform.ai-evaluations.manage' => ['Manage AI evaluation runs', 'Create and run bounded synthetic AI evaluations.'],
            'platform.ai-evaluations.review' => ['Review AI evaluation runs', 'Approve or reject human-reviewed AI evaluation release gates.'],
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
        $permissions = Permission::query()->whereIn('key', ['platform.ai-evaluations.read', 'platform.ai-evaluations.manage', 'platform.ai-evaluations.review'])->get();
        foreach ($permissions as $permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
