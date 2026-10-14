<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('category', 60)->default('2 - Comercial');
            $t->string('description', 500);
            $t->date('due_date')->nullable();
            $t->string('stage', 60)->nullable();
            $t->string('origin', 60)->default('Manual');
            $t->string('commitment', 255)->nullable();
            $t->decimal('expected_value', 18, 2)->nullable();
            $t->string('status', 20)->default('Pendiente');
            $t->text('notes')->nullable();
            $t->string('source_document', 40)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('product_combo_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('combo_id')->constrained('products')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');
            $t->decimal('quantity', 18, 4);
            $t->timestamps();
            $t->unique(['combo_id', 'product_id']);
        });

        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->unsignedSmallInteger('year');
            $t->string('name', 120);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $t->json('months');
            $t->timestamps();
            $t->unique(['budget_id', 'account_id']);
        });

        Schema::create('recurring_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('sales_document_id')->constrained();
            $t->foreignId('contact_id')->constrained();
            $t->string('frequency', 20)->default('Mensual');
            $t->date('start_date');
            $t->date('next_date');
            $t->unsignedInteger('times')->nullable();
            $t->unsignedInteger('generated')->default(0);
            $t->boolean('active')->default(true);
            $t->string('last_document', 40)->nullable();
            $t->string('last_error')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('journal_entries', function (Blueprint $t) {
            $t->boolean('favorite')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $t) => $t->dropColumn('favorite'));
        Schema::dropIfExists('recurring_invoices');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('product_combo_items');
        Schema::dropIfExists('crm_tasks');
    }
};
