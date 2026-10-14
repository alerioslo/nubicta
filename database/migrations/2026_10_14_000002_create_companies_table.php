<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('person_type', 10)->default('juridica'); // juridica | natural
            $table->string('identification_type', 4)->default('31'); // catalogo DIAN, 31 = NIT
            $table->string('nit', 20)->unique();
            $table->string('dv', 1)->nullable();
            $table->string('business_name');
            $table->string('trade_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('tax_regime', 4)->default('48'); // 48 responsable IVA, 49 no responsable
            $table->json('fiscal_responsibilities')->nullable(); // O-13, O-15, O-23, O-47, R-99-PN
            $table->string('ciiu', 10)->nullable();
            $table->string('address')->nullable();
            $table->string('country_code', 2)->default('CO');
            $table->string('department_code', 2)->nullable();
            $table->string('municipality_code', 5)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('currency', 3)->default('COP');
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->date('closed_until')->nullable(); // cierre de periodo: no se permiten documentos con fecha <= a esta
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
