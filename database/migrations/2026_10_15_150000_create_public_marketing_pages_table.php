<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_marketing_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('slug', 120);
            $table->string('category', 40);
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->json('sections');
            $table->unsignedInteger('version');
            $table->string('status', 20);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['slug', 'version']);
            $table->index(['category', 'status', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_marketing_pages');
    }
};
