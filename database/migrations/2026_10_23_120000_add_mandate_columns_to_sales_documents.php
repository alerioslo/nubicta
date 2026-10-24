<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Factura por mandato: referencia del cliente, mandante, contado y factura externa. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->string('external_reference', 120)->nullable()->after('name');
            $table->string('request_hash', 64)->nullable()->after('external_reference');
            $table->string('payment_form', 12)->nullable()->after('request_hash');
            $table->json('principal')->nullable()->after('payment_form');
            $table->json('external_invoice')->nullable()->after('principal');
            $table->unique(['company_id', 'type', 'external_reference'], 'sales_documents_external_reference_unique');
        });

        Schema::table('sales_document_items', function (Blueprint $table) {
            $table->string('role', 12)->nullable()->after('description');
            $table->string('tax_code', 4)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropUnique('sales_documents_external_reference_unique');
            $table->dropColumn(['external_reference', 'request_hash', 'payment_form', 'principal', 'external_invoice']);
        });
        Schema::table('sales_document_items', function (Blueprint $table) {
            $table->dropColumn(['role', 'tax_code']);
        });
    }
};
