<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('payroll_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedSmallInteger('code');
            $table->string('description', 160);
            $table->string('type', 20); // Ingreso | Deducción
            $table->string('electronic_group', 80)->nullable();
            $table->decimal('factor', 8, 4)->nullable();
            $table->text('employee_base')->nullable();
            $table->text('company_base')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('payroll_account_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedSmallInteger('concept_code');
            $table->string('concept', 160);
            $table->string('group', 40); // Administración | Ventas | Producción
            $table->foreignId('account_id')->nullable()->constrained();
            $table->timestamps();
            $table->unique(['company_id', 'concept_code', 'group']);
        });

        Schema::create('frequent_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('kind', 10); // gasto | ingreso
            $table->string('name', 160);
            $table->foreignId('account_id')->nullable()->constrained();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'kind', 'name']);
        });

        Schema::create('fixed_asset_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('code', 10);
            $table->string('name', 120);
            $table->foreignId('purchase_account_id')->nullable()->constrained('accounts');
            $table->foreignId('depreciation_account_id')->nullable()->constrained('accounts');
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts');
            $table->foreignId('fiscal_depreciation_account_id')->nullable()->constrained('accounts');
            $table->foreignId('fiscal_expense_account_id')->nullable()->constrained('accounts');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->boolean('locked')->default(false);
            $table->string('locked_by', 120)->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'year', 'month']);
        });

        Schema::create('free_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('field_type', 40);
            $table->string('name', 120);
            $table->string('characteristics', 120)->nullable();
            $table->text('list_data')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('measurement_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['measurement_units', 'free_fields', 'period_locks', 'fixed_asset_groups', 'frequent_concepts', 'payroll_account_params', 'payroll_concepts', 'job_positions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
