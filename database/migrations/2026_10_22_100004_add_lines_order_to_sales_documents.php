<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 0 = orden de lineas tomado del listado de Siigo (sin confirmar), 1 = confirmado con el detalle, 2 = el detalle no coincide. */
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->unsignedTinyInteger('lines_order')->default(0)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn('lines_order');
        });
    }
};
