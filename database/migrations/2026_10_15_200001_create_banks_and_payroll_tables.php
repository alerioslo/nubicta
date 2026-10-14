<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('account_id')->constrained('accounts');
            $table->string('name');
            $table->string('kind', 10)->default('banco'); // caja | banco
            $table->string('bank_name')->nullable();
            $table->string('number', 40)->nullable();
            $table->decimal('opening_balance', 18, 2)->default(0); // saldo traido de Siigo
            $table->dateTime('opening_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'account_id']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('contact_id')->constrained();
            $table->string('contract_number', 40)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('salary', 18, 2)->default(0);
            $table->string('contract_type', 40)->nullable(); // Indefinido, Fijo, Obra o labor, Aprendizaje
            $table->string('payment_frequency', 20)->default('Mensual');
            $table->string('position')->nullable();
            $table->string('eps')->nullable();
            $table->string('pension_fund')->nullable();
            $table->string('severance_fund')->nullable();
            $table->string('arl_risk', 5)->nullable();
            $table->string('bank_account')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'contact_id', 'contract_number']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name');
            $table->string('settlement_type', 20)->default('Mensual');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 20)->default('Borrador'); // Borrador | Terminada
            $table->string('source', 10)->default('erp');
            $table->timestamps();
            $table->unique(['company_id', 'period_start', 'period_end', 'name']);
        });

        Schema::create('payroll_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('employee_id')->nullable()->constrained();
            $table->foreignId('contact_id')->nullable()->constrained();
            $table->foreignId('payroll_run_id')->nullable()->constrained();
            $table->date('generated_at');
            $table->string('type', 30)->default('Soporte de pago'); // Soporte de pago | Nota de ajuste
            $table->string('consecutive', 30);
            $table->string('contract_number', 40)->nullable();
            $table->decimal('net', 18, 2)->default(0);
            $table->string('status', 20)->default('Pendiente');
            $table->string('cune', 120)->nullable();
            $table->string('source', 10)->default('erp');
            $table->timestamps();
            $table->unique(['company_id', 'consecutive']);
        });
    }

    public function down(): void
    {
        foreach (['payroll_documents', 'payroll_runs', 'employees', 'bank_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
