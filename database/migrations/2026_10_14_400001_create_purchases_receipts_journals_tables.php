<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Compras: FC factura de compra/gasto, DS documento soporte, ND nota debito compras, NA nota ajuste DS */
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('source', 10)->default('erp');
            $table->string('type', 2)->default('FC');
            $table->foreignId('document_type_id')->nullable()->constrained();
            $table->unsignedBigInteger('number');
            $table->string('name', 40);
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->foreignId('contact_id')->constrained();
            $table->string('supplier_prefix', 20)->nullable(); // factura del proveedor
            $table->string('supplier_number', 40)->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained();
            $table->string('currency', 3)->default('COP');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('withholding_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);
            $table->text('observations')->nullable();
            $table->string('status', 12)->default('issued');
            $table->string('cuds', 120)->nullable(); // documento soporte electronico
            $table->string('dian_status', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'date']);
            $table->index(['contact_id', 'date']);
        });

        Schema::create('purchase_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('item_type', 12)->default('Product'); // Product | Account | FixedAsset
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('account_id')->nullable()->constrained();
            $table->string('code', 40)->nullable();
            $table->string('description', 500);
            $table->foreignId('warehouse_id')->nullable()->constrained();
            $table->decimal('quantity', 18, 4);
            $table->decimal('price', 18, 6);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('tax_percentage', 6, 2)->nullable();
            $table->index('product_id');
        });

        Schema::create('purchase_document_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
        });

        /* Retenciones a nivel de documento (ReteIVA / ReteICA de compras) */
        Schema::create('purchase_document_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained();
            $table->string('name', 80);
            $table->string('type', 20);
            $table->decimal('percentage', 8, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
        });

        Schema::create('purchase_document_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained();
            $table->string('name', 120)->nullable();
            $table->decimal('value', 18, 2);
            $table->date('due_date')->nullable();
        });

        /* Recibos: RC recibo de caja (clientes), RP recibo de pago / egreso (proveedores) */
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('source', 10)->default('erp');
            $table->string('type', 2); // RC | RP
            $table->string('receipt_type', 20)->default('DebtPayment'); // DebtPayment | AdvancePayment | Detailed
            $table->foreignId('document_type_id')->nullable()->constrained();
            $table->unsignedBigInteger('number');
            $table->string('name', 40);
            $table->date('date');
            $table->foreignId('contact_id')->constrained();
            $table->foreignId('payment_method_id')->nullable()->constrained();
            $table->string('payment_name', 120)->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->text('observations')->nullable();
            $table->string('status', 12)->default('issued');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'type', 'date']);
        });

        Schema::create('receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->string('due_prefix', 20)->nullable(); // FV-2, DS, EXTE
            $table->unsignedBigInteger('due_number')->nullable();
            $table->unsignedSmallInteger('due_quote')->default(1);
            $table->date('due_date')->nullable();
            $table->foreignId('sales_document_id')->nullable()->constrained();
            $table->foreignId('purchase_document_id')->nullable()->constrained();
            $table->foreignId('account_id')->nullable()->constrained(); // recibos detallados / anticipos
            $table->string('description', 255)->nullable();
            $table->decimal('value', 18, 2);
        });

        /* Asientos contables: comprobantes CC importados y, desde la fase 10, la contabilizacion automatica */
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('source', 10)->default('erp');
            $table->foreignId('document_type_id')->nullable()->constrained();
            $table->string('source_type')->nullable(); // documento que genero el asiento
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('number')->nullable();
            $table->string('name', 40);
            $table->date('date');
            $table->text('observations')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('status', 12)->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->date('date');
            $table->foreignId('account_id')->constrained();
            $table->foreignId('contact_id')->nullable()->constrained();
            $table->foreignId('cost_center_id')->nullable()->constrained();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('due_prefix', 20)->nullable();
            $table->unsignedBigInteger('due_number')->nullable();
            $table->date('due_date')->nullable();
            $table->index(['company_id', 'account_id', 'date']);
            $table->index(['contact_id', 'account_id']);
        });
    }

    public function down(): void
    {
        foreach (['journal_lines', 'journal_entries', 'receipt_items', 'receipts', 'purchase_document_payments',
            'purchase_document_retentions', 'purchase_document_item_taxes', 'purchase_document_items', 'purchase_documents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
