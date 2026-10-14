<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radian_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->date('registered_at');
            $table->string('cufe', 120);
            $table->string('supplier_nit', 20)->nullable();
            $table->string('document_number', 40)->nullable();
            $table->string('supplier_name')->nullable();
            $table->foreignId('purchase_document_id')->nullable()->constrained();
            $table->string('reception_status', 20)->default('Pendiente'); // 030 acuse de recibo
            $table->string('goods_status', 20)->default('Pendiente');     // 032 recibo del bien o servicio
            $table->string('acceptance_status', 20)->default('Pendiente'); // 033 aceptacion / 031 reclamo
            $table->boolean('finished')->default(false);
            $table->string('external_ref', 40)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'cufe']);
        });

        Schema::create('inventory_costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('executed_at')->nullable();
            $table->string('status', 20)->default('Sin costear');
            $table->timestamps();
            $table->unique(['company_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_costings');
        Schema::dropIfExists('radian_events');
    }
};
