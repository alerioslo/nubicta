<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('kind', 4); // FV | DS
            $table->string('kind_label');
            $table->string('resolution_number', 30);
            $table->date('resolution_date')->nullable();
            $table->string('prefix', 10)->nullable();
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('technical_key')->nullable();
            $table->string('linked_document', 20)->nullable(); // tipo de comprobante asociado en Siigo (FV-2, DS-1)
            $table->string('source', 10)->default('siigo');
            $table->timestamps();
            $table->unique(['company_id', 'resolution_number', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_resolutions');
    }
};
