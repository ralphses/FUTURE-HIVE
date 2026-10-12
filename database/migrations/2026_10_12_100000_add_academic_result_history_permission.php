<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['key' => 'academic.results.history.read'], [
            'label' => 'Read student result history',
            'description' => 'Read safe published result history in the selected academic scope.',
            'scope' => 'school',
            'is_active' => true,
        ]);

        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'academic.results.history.read')->first();
        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
