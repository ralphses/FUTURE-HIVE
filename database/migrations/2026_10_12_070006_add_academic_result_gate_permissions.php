<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'academic.results.approve' => ['Approve academic results', 'Approve complete result runs for publication gating.', 'school'],
            'academic.results.publish' => ['Publish academic results', 'Publish an approved result run through the server-controlled gate.', 'school'],
        ];

        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            $roles = $key === 'academic.results.publish'
                ? ['proprietor', 'principal']
                : ['school_admin', 'proprietor', 'principal'];

            foreach ($roles as $roleKey) {
                Role::query()->where('key', $roleKey)->first()?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.approve', 'academic.results.publish'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
