<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration as BaseMigration;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use Illuminate\Support\Facades\Schema as SchemaFacade;

return new class extends BaseMigration
{
    public function up(): void
    {
        SchemaFacade::create('financial_opening_balance_imports', function (SchemaBlueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('import_batch_id')->constrained('import_batches');
            $table->foreignId('import_commit_id')->nullable()->constrained('import_commits')->nullOnDelete();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('template_key', 64);
            $table->string('template_version', 16);
            $table->string('status', 32);
            $table->json('counts')->nullable();
            $table->json('error_summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->unique('import_batch_id');
            $table->index(['school_id', 'status']);
        });

        SchemaFacade::create('financial_opening_balances', function (SchemaBlueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('financial_opening_balance_import_id')->constrained('financial_opening_balance_imports');
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->unsignedInteger('row_number');
            $table->decimal('opening_balance', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('as_of_date')->nullable();
            $table->string('provenance_reference', 160)->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['financial_opening_balance_import_id', 'row_number']);
            $table->index(['school_id', 'student_id', 'as_of_date']);
        });

        if (SchemaFacade::getConnection()->getDriverName() === 'pgsql') {
            SchemaFacade::getConnection()->statement("ALTER TABLE financial_opening_balance_imports ADD CONSTRAINT financial_opening_balance_import_status_allowed CHECK (status IN ('unverified', 'accepted', 'rejected'))");
            SchemaFacade::getConnection()->statement("ALTER TABLE financial_opening_balances ADD CONSTRAINT financial_opening_balance_status_allowed CHECK (status IN ('unverified', 'rejected'))");
        }
    }

    public function down(): void
    {
        SchemaFacade::dropIfExists('financial_opening_balances');
        SchemaFacade::dropIfExists('financial_opening_balance_imports');
    }
};
