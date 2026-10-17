<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Costo promedio y salidas del mes por producto (Informe costeo mensual), importado de Siigo para los meses anteriores a Nubicta. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_monthly_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 60);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->decimal('average_cost', 20, 6)->default(0);
            $t->decimal('quantity_out', 18, 4)->default(0);
            $t->decimal('total_cost', 20, 6)->default(0);
            $t->string('source', 10)->default('siigo');
            $t->timestamps();
            $t->unique(['company_id', 'year', 'month', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_monthly_costs');
    }
};
