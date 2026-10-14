<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Impuestos (retenciones) y descuentos o ajustes de cada documento abonado en un recibo, como en Siigo. */
    public function up(): void
    {
        Schema::create('receipt_item_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('frequent_concept_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->string('name');
            $table->decimal('base', 18, 2)->default(0);
            $table->decimal('value', 18, 2);
        });
        Schema::table('receipt_items', function (Blueprint $table) {
            $table->decimal('adjustments', 18, 2)->default(0)->after('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_item_adjustments');
        Schema::table('receipt_items', fn (Blueprint $table) => $table->dropColumn('adjustments'));
    }
};
