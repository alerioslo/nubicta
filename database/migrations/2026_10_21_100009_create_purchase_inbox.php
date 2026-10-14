<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_inbox_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('cufe', 120);
            $table->string('kind', 30);
            $table->string('number', 40);
            $table->date('issue_date')->nullable();
            $table->string('supplier_nit', 20)->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 20)->default('Pendiente');
            $table->foreignId('purchase_document_id')->nullable();
            $table->string('xml_path');
            $table->string('source', 20)->default('correo');
            $table->string('received_from')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'cufe']);
        });

        Schema::create('dian_inbox_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('received')->default(0);
            $table->string('observations', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('expense_supports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 20)->default('Sin causar');
            $table->foreignId('purchase_document_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_supports');
        Schema::dropIfExists('dian_inbox_syncs');
        Schema::dropIfExists('dian_inbox_documents');
    }
};
