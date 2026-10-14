<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Terceros: clientes, proveedores, empleados y otros (un mismo tercero puede tener varios roles) */
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_employee')->default(false);
            $table->boolean('is_other')->default(false);
            $table->string('person_type', 10)->default('natural'); // natural | juridica
            $table->string('identification_type', 4)->default('13');
            $table->string('identification', 20);
            $table->string('dv', 1)->nullable();
            $table->unsignedSmallInteger('branch_office')->default(0);
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('business_name')->nullable();
            $table->string('name'); // nombre completo o razon social, para busquedas y listados
            $table->string('trade_name')->nullable();
            $table->boolean('vat_responsible')->default(false);
            $table->json('fiscal_responsibilities')->nullable();
            $table->string('address')->nullable();
            $table->string('country_code', 2)->default('CO');
            $table->string('department_code', 2)->nullable();
            $table->string('municipality_code', 5)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone_indicative', 6)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_extension', 10)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('billing_first_name')->nullable();
            $table->string('billing_last_name')->nullable();
            $table->string('email')->nullable(); // correo para documentos electronicos
            $table->foreignId('seller_id')->nullable()->constrained();
            $table->foreignId('collector_id')->nullable()->constrained('sellers');
            $table->foreignId('price_list_id')->nullable()->constrained();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->text('observations')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'identification', 'branch_office']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('contact_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_indicative', 6)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('position')->nullable();
            $table->boolean('is_payer')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_people');
        Schema::dropIfExists('contacts');
    }
};
