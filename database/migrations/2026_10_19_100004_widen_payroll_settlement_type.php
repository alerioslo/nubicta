<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->string('settlement_type', 60)->default('Mensual')->change());
    }

    public function down(): void
    {
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->string('settlement_type', 20)->default('Mensual')->change());
    }
};
