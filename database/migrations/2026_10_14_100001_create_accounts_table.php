<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('accounts');
            $table->string('code', 12);
            $table->string('name');
            $table->unsignedTinyInteger('level'); // 1 clase, 2 grupo, 3 cuenta, 4 subcuenta, 5 auxiliar
            $table->string('nature', 7); // debito | credito
            $table->string('category', 40)->nullable(); // Caja - Bancos, Cuentas por cobrar, Ingresos...
            $table->string('relation', 80)->nullable(); // Formas de pago, Impuestos - Ventas, Grupo de inventarios - Costo...
            $table->string('due_type', 12)->nullable(); // cartera | proveedores
            $table->boolean('fiscal_difference')->default(false);
            $table->boolean('transactional')->default(false);
            $table->boolean('requires_third_party')->default(true);
            $table->boolean('requires_cost_center')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
