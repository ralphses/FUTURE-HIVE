<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 16);
            $table->string('canonical_value', 320);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
            $table->index(['type', 'canonical_value']);
        });

        DB::table('users')->orderBy('id')->eachById(function (object $user): void {
            DB::table('user_contacts')->insert([
                'user_id' => $user->id,
                'type' => 'email',
                'canonical_value' => mb_strtolower(trim($user->email)),
                'verified_at' => $user->email_verified_at,
                'is_primary' => true,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]);
        });

        DB::statement('CREATE UNIQUE INDEX user_contacts_active_value_unique ON user_contacts (type, canonical_value) WHERE superseded_at IS NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
        });

        DB::table('user_contacts')->where('type', 'email')->whereNull('superseded_at')->orderBy('id')->eachById(function (object $contact): void {
            DB::table('users')->where('id', $contact->user_id)->update([
                'email' => $contact->canonical_value,
                'email_verified_at' => $contact->verified_at,
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->unique()->change();
        });

        DB::statement('DROP INDEX IF EXISTS user_contacts_active_value_unique');
        Schema::dropIfExists('user_contacts');
    }
};
