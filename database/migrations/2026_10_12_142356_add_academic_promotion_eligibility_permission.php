<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use App\Contexts\Identity\Domain\Services\AuthorizationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $definition = AuthorizationCatalogue::permissions()['academic.results.promotion-eligibility.read'];
        Permission::query()->firstOrCreate(['key' => 'academic.results.promotion-eligibility.read'], [
            'public_id' => (string) Str::uuid7(),
            'label' => $definition['label'],
            'description' => $definition['description'],
            'scope' => 'school',
            'is_active' => true,
        ]);
        foreach (['school_admin', 'proprietor', 'principal', 'hod_reviewer'] as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->first();
            $permission = Permission::query()->where('key', 'academic.results.promotion-eligibility.read')->first();
            if ($role && $permission) {
                $role->permissions()->syncWithoutDetaching([$permission->getKey()]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permission = Permission::query()->where('key', 'academic.results.promotion-eligibility.read')->first();
        if ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
