<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Lineas de producto del modulo de deposito (mascotas, bovinos, ...). El codigo del producto inicia con el codigo de la linea. */
        Schema::create('product_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('code', 6);
            $table->string('name');
            $table->unsignedTinyInteger('code_digits')->default(4); // digitos del consecutivo despues del codigo de linea
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 12)->default('Product'); // Product | Service | Combo
            $table->foreignId('account_group_id')->nullable()->constrained();
            $table->foreignId('product_line_id')->nullable()->constrained();
            $table->string('unit_code', 6)->default('94');
            $table->string('unit_label', 40)->default('unidad');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('barcode', 60)->nullable()->index();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('tariff', 20)->nullable(); // partida arancelaria
            $table->boolean('stock_control')->default(true);
            $table->boolean('visible_in_sales')->default(true);
            $table->string('tax_classification', 10)->default('Taxed'); // Taxed | Exempt | Excluded
            $table->boolean('tax_included')->default(false);
            $table->decimal('stock', 18, 4)->default(0); // saldo total (cache de warehouse_stock)
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('min_stock', 18, 4)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('product_tax', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained();
            $table->decimal('value', 18, 2)->nullable(); // para impuestos por valor fijo
            $table->primary(['product_id', 'tax_id']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained();
            $table->string('currency', 3)->default('COP');
            $table->decimal('value', 18, 4);
            $table->unique(['product_id', 'price_list_id', 'currency']);
        });

        Schema::create('warehouse_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->unique(['product_id', 'warehouse_id']);
        });

        /* Kardex: cada entrada o salida de inventario con su saldo resultante */
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->date('date');
            $table->string('source_type')->nullable(); // modelo del documento origen
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('document', 40)->nullable(); // FV-2-71107
            $table->string('concept', 40); // venta, compra, devolucion, ajuste, traslado, saldo_inicial
            $table->decimal('quantity', 18, 4); // positivo entra, negativo sale
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->decimal('balance_quantity', 18, 4)->default(0);
            $table->decimal('balance_cost', 18, 2)->default(0);
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->timestamps();
            $table->index(['product_id', 'date', 'id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        foreach (['inventory_movements', 'warehouse_stock', 'product_prices', 'product_tax', 'products', 'product_lines'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
