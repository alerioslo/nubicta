<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_retentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 30);
            $table->decimal('percentage', 9, 4)->default(0);
            $table->decimal('base', 18, 2)->default(0);
            $table->decimal('value', 18, 2)->default(0);
        });

        Schema::table('purchase_document_retentions', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_document_retentions', 'base')) {
                $table->decimal('base', 18, 2)->default(0)->after('percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_retentions');
        Schema::table('purchase_document_retentions', fn (Blueprint $table) => $table->dropColumn('base'));
    }
};
