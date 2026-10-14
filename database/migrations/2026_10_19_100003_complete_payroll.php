<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('pay_mode', 12)->default('nomina')->after('active');
            $t->string('payroll_group', 60)->default('Administración')->after('pay_mode');
            $t->string('contributor_type', 80)->nullable()->after('payroll_group');
            $t->string('contributor_subtype', 80)->nullable()->after('contributor_type');
            $t->boolean('integral_salary')->default(false)->after('contributor_subtype');
            $t->boolean('transport_aid')->default(true)->after('integral_salary');
            $t->decimal('health_rate', 6, 4)->default(0.04)->after('transport_aid');
            $t->decimal('pension_rate', 6, 4)->default(0.04)->after('health_rate');
            $t->string('ccf', 120)->nullable()->after('pension_rate');
            $t->string('arl', 120)->nullable()->after('ccf');
            $t->unsignedTinyInteger('arl_class')->default(1)->after('arl');
            $t->foreignId('eps_contact_id')->nullable()->after('arl_class')->constrained('contacts')->nullOnDelete();
            $t->foreignId('pension_contact_id')->nullable()->after('eps_contact_id')->constrained('contacts')->nullOnDelete();
            $t->foreignId('severance_contact_id')->nullable()->after('pension_contact_id')->constrained('contacts')->nullOnDelete();
            $t->foreignId('arl_contact_id')->nullable()->after('severance_contact_id')->constrained('contacts')->nullOnDelete();
            $t->foreignId('ccf_contact_id')->nullable()->after('arl_contact_id')->constrained('contacts')->nullOnDelete();
            $t->foreignId('cost_center_id')->nullable()->after('ccf_contact_id')->constrained()->nullOnDelete();
            $t->string('payment_method', 60)->nullable()->after('cost_center_id');
            $t->string('bank_name', 120)->nullable()->after('payment_method');
            $t->string('bank_account_type', 30)->nullable()->after('bank_name');
            $t->decimal('vacation_days_opening', 8, 2)->default(0)->after('bank_account_type');
            $t->string('termination_reason', 120)->nullable()->after('vacation_days_opening');
        });

        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->string('kind', 20)->default('nomina')->after('settlement_type');
            $t->string('frequency', 12)->default('Mensual')->after('kind');
            $t->date('payment_date')->nullable()->after('period_end');
            $t->decimal('earnings', 18, 2)->default(0)->after('total');
            $t->decimal('deductions', 18, 2)->default(0)->after('earnings');
            $t->decimal('employer_cost', 18, 2)->default(0)->after('deductions');
            $t->decimal('provisions', 18, 2)->default(0)->after('employer_cost');
            $t->string('payment_document', 40)->nullable()->after('provisions');
            $t->unsignedBigInteger('created_by')->nullable()->after('source');
            $t->dateTime('posted_at')->nullable()->after('created_by');
            $t->dateTime('paid_at')->nullable()->after('posted_at');
        });

        Schema::table('payroll_documents', function (Blueprint $t) {
            $t->decimal('worked_days', 6, 2)->default(0)->after('contract_number');
            $t->decimal('salary', 18, 2)->default(0)->after('worked_days');
            $t->decimal('ibc', 18, 2)->default(0)->after('salary');
            $t->decimal('earnings', 18, 2)->default(0)->after('ibc');
            $t->decimal('deductions', 18, 2)->default(0)->after('earnings');
            $t->decimal('employer_cost', 18, 2)->default(0)->after('deductions');
            $t->decimal('provisions', 18, 2)->default(0)->after('employer_cost');
            $t->string('ledger_document', 40)->nullable()->after('provisions');
        });

        Schema::create('payroll_novelties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('concept', 40);
            $t->decimal('quantity', 10, 2)->default(0);
            $t->decimal('value', 18, 2)->default(0);
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('payroll_document_id')->constrained()->cascadeOnDelete();
            $t->string('concept', 40);
            $t->string('label', 120);
            $t->string('type', 12);
            $t->decimal('quantity', 10, 2)->default(0);
            $t->decimal('rate', 10, 6)->default(0);
            $t->decimal('base', 18, 2)->default(0);
            $t->decimal('value', 18, 2);
            $t->boolean('salary_base')->default(false);
            $t->timestamps();
            $t->index(['payroll_document_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_novelties');
        Schema::table('payroll_documents', fn (Blueprint $t) => $t->dropColumn(['worked_days', 'salary', 'ibc', 'earnings', 'deductions', 'employer_cost', 'provisions', 'ledger_document']));
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropColumn(['kind', 'frequency', 'payment_date', 'earnings', 'deductions', 'employer_cost', 'provisions', 'payment_document', 'created_by', 'posted_at', 'paid_at']));
        Schema::table('employees', function (Blueprint $t) {
            foreach (['eps_contact_id', 'pension_contact_id', 'severance_contact_id', 'arl_contact_id', 'ccf_contact_id', 'cost_center_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['pay_mode', 'payroll_group', 'contributor_type', 'contributor_subtype', 'integral_salary', 'transport_aid', 'health_rate', 'pension_rate',
                'ccf', 'arl', 'arl_class', 'payment_method', 'bank_name', 'bank_account_type', 'vacation_days_opening', 'termination_reason']);
        });
    }
};
