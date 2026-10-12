<?php

declare(strict_types=1);

use App\Contexts\Identity\Domain\Models\Permission;
use App\Contexts\Identity\Domain\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::query()->firstOrCreate(['key' => 'academic.results.exports.read'], [
            'label' => 'Export academic results',
            'description' => 'Download safe CSV or PDF exports of published results within the selected academic scope.',
            'scope' => 'school',
            'is_active' => true,
        ]);

        Role::query()->whereIn('key', ['school_admin', 'proprietor', 'principal', 'hod_reviewer', 'teacher'])->get()->each(
            fn (Role $role): mixed => $role->permissions()->syncWithoutDetaching([$permission->getKey()]),
        );
    }

    public function down(): void
    {
        $permission = Permission::query()->where('key', 'academic.results.exports.read')->first();
        if ($permission instanceof Permission) {
            $permission->roles()->detach();
            $permission->delete();
        }
    }
};
