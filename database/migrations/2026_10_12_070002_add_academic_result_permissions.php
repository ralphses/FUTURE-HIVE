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
            'academic.results.compute' => ['Compute academic results', 'Generate school-scoped result projections from approved assessment submissions.', 'school'],
            'academic.results.read' => ['Read academic results', 'Read safe school-scoped result projections.', 'school'],
        ];

        foreach ($permissions as $key => [$label, $description, $scope]) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $label, 'description' => $description, 'scope' => $scope, 'is_active' => true]);
            foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer'] as $roleKey) {
                $role = Role::query()->where('key', $roleKey)->first();
                $role?->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['academic.results.compute', 'academic.results.read'] as $key) {
            $permission = Permission::query()->where('key', $key)->first();
            if ($permission instanceof Permission) {
                $permission->roles()->detach();
                $permission->delete();
            }
        }
    }
};
