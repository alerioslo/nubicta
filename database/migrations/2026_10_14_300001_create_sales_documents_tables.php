<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Documentos de venta: FV factura, NC nota credito, ND nota debito, CT cotizacion, RM remision */
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('source', 10)->default('erp'); // erp | siigo
            $table->string('type', 2);
            $table->foreignId('document_type_id')->nullable()->constrained();
            $table->string('prefix', 10)->nullable(); // prefijo DIAN (FES)
            $table->unsignedBigInteger('number');
            $table->string('name', 40); // FV-2-71107
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->foreignId('contact_id')->constrained();
            $table->foreignId('seller_id')->nullable()->constrained();
            $table->foreignId('cost_center_id')->nullable()->constrained();
            $table->foreignId('related_document_id')->nullable()->constrained('sales_documents'); // NC/ND -> factura
            $table->string('related_name', 40)->nullable();
            $table->string('reason', 10)->nullable(); // concepto de correccion DIAN para NC/ND
            $table->string('currency', 3)->default('COP');
            $table->decimal('exchange_rate', 14, 4)->default(1);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('withholding_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);
            $table->text('observations')->nullable();
            $table->string('status', 12)->default('issued'); // draft | issued | annulled
            $table->string('cufe', 120)->nullable()->index();
            $table->string('dian_status', 20)->nullable(); // Draft | Accepted | Rejected | Pending
            $table->string('mail_status', 20)->nullable();
            $table->string('public_url', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'type', 'date']);
            $table->index(['contact_id', 'date']);
        });

        Schema::create('sales_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('product_id')->nullable()->constrained();
            $table->string('code', 40)->nullable();
            $table->string('description', 500);
            $table->foreignId('warehouse_id')->nullable()->constrained();
            $table->foreignId('seller_id')->nullable()->constrained();
            $table->decimal('quantity', 18, 4);
            $table->decimal('price', 18, 6); // precio unitario antes de impuestos
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0); // cantidad * precio - descuento
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('tax_percentage', 6, 2)->nullable(); // IVA principal, para informes
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->index('product_id');
        });

        Schema::create('sales_document_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
        });

        Schema::create('sales_document_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained();
            $table->string('name', 120)->nullable();
            $table->decimal('value', 18, 2);
            $table->date('due_date')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['sales_document_payments', 'sales_document_item_taxes', 'sales_document_items', 'sales_documents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
