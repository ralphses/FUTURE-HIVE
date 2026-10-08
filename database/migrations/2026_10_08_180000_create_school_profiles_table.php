<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained('schools')->cascadeOnDelete();
            $table->string('contact_type', 16)->nullable();
            $table->string('canonical_contact', 191)->nullable();
            $table->string('address_line1', 160)->nullable();
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->default('NG');
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->string('logo_public_id', 255)->nullable();
            $table->string('logo_format', 32)->nullable();
            $table->string('logo_resource_type', 16)->nullable();
            $table->timestamps();
            $table->index(['contact_type', 'canonical_contact']);
        });

        $now = now();
        $schools = DB::table('schools')->select('id')->get();

        foreach ($schools as $school) {
            DB::table('school_profiles')->insert([
                'school_id' => $school->id,
                'country' => 'NG',
                'timezone' => 'Africa/Lagos',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('school_profiles');
    }
};
