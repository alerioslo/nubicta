<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->date('date');
            $table->string('account_code', 12);
            $table->foreignId('account_id')->nullable()->constrained();
            $table->string('identification', 20)->nullable();
            $table->string('branch', 4)->default('0');
            $table->string('contact_name')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained();
            $table->string('document', 30);
            $table->string('document_type', 6);
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('source', 20)->default('siigo'); // siigo | erp
            $table->index(['company_id', 'account_code', 'date']);
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'identification']);
            $table->index(['company_id', 'document']);
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('fixed_asset_group_id')->nullable()->constrained();
            $table->string('code', 20);
            $table->string('name', 160);
            $table->boolean('active')->default(true);
            $table->string('created_by', 120)->nullable();
            $table->dateTime('siigo_created_at')->nullable();
            $table->string('modified_by', 120)->nullable();
            $table->dateTime('siigo_modified_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('ledger_lines');
    }
};
