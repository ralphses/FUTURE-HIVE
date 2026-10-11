<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['key' => 'teaching.worklist.read'], ['name' => 'teaching.worklist.read', 'description' => 'View the authenticated teacher worklist.']);
        Role::query()->whereIn('key', ['school_admin', 'teacher', 'hod_reviewer', 'principal', 'proprietor'])->get()->each(fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $permission = Permission::query()->where('key', 'teaching.worklist.read')->first();
        if (! $permission instanceof Permission) {
            return;
        }
        Role::query()->get()->each(fn (Role $role): mixed => $role->permissions()->detach($permission->getKey()));
        $permission->delete();
    }
};
