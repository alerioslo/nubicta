<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            $table->foreignId('product_id');
            $table->date('date');
            $table->unsignedBigInteger('position');
            $table->string('document', 40);
            $table->decimal('quantity', 18, 4);
            $table->decimal('value', 20, 6);
            $table->decimal('balance_quantity', 18, 4);
            $table->decimal('balance_value', 20, 6);
            $table->index(['company_id', 'product_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_history');
    }
};
