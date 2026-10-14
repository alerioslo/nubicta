<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name');
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->string('code', 2)->primary();
            $table->string('name');
        });

        Schema::create('municipalities', function (Blueprint $table) {
            $table->string('code', 5)->primary();
            $table->string('department_code', 2)->index();
            $table->string('name');
            $table->foreign('department_code')->references('code')->on('departments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('countries');
    }
};
