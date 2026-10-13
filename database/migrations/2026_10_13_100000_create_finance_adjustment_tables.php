<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_adjustment_programs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('code', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('adjustment_type', 20);
            $table->string('calculation_method', 20);
            $table->unsignedBigInteger('fixed_amount_minor_units')->nullable();
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->boolean('allows_multiple')->default(false);
            $table->json('eligibility')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('fee_invoice_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('fee_invoice_id')->constrained('fee_invoices');
            $table->foreignId('fee_invoice_line_id')->nullable()->constrained('fee_invoice_lines');
            $table->foreignId('fee_adjustment_program_id')->constrained('fee_adjustment_programs');
            $table->string('adjustment_type', 20);
            $table->string('calculation_method', 20);
            $table->unsignedBigInteger('base_minor_units');
            $table->unsignedBigInteger('amount_minor_units');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500);
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'fee_invoice_id', 'status']);
            $table->index(['school_id', 'fee_adjustment_program_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fee_adjustment_programs ADD CONSTRAINT fee_adjustment_program_type_allowed CHECK (adjustment_type IN ('discount', 'scholarship', 'waiver'))");
            DB::statement("ALTER TABLE fee_adjustment_programs ADD CONSTRAINT fee_adjustment_program_method_allowed CHECK (calculation_method IN ('fixed', 'percentage'))");
            DB::statement("ALTER TABLE fee_adjustment_programs ADD CONSTRAINT fee_adjustment_program_status_allowed CHECK (status IN ('draft', 'active', 'retired'))");
            DB::statement("ALTER TABLE fee_adjustment_programs ADD CONSTRAINT fee_adjustment_program_amount_allowed CHECK ((calculation_method = 'fixed' AND fixed_amount_minor_units IS NOT NULL AND percentage_basis_points IS NULL) OR (calculation_method = 'percentage' AND fixed_amount_minor_units IS NULL AND percentage_basis_points IS NOT NULL AND percentage_basis_points BETWEEN 0 AND 10000))");
            DB::statement("ALTER TABLE fee_invoice_adjustments ADD CONSTRAINT fee_invoice_adjustment_status_allowed CHECK (status IN ('pending', 'approved', 'rejected', 'applied', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_invoice_adjustments');
        Schema::dropIfExists('fee_adjustment_programs');
    }
};
