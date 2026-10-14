<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Documento soporte de pago de nomina electronica (mensual por empleado) y sus notas de ajuste. */
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->char('period', 7)->nullable()->after('generated_at')->index();
            $table->unsignedInteger('number')->nullable()->after('consecutive');
            $table->foreignId('predecessor_id')->nullable()->after('payroll_run_id')->constrained('payroll_documents')->nullOnDelete();
            $table->unsignedTinyInteger('note_type')->nullable()->after('predecessor_id');
            $table->string('xml_path')->nullable()->after('cune');
            $table->string('dian_message', 500)->nullable()->after('xml_path');
        });
        DB::table('payroll_documents')->whereIn('type', ['Soporte de pago', 'Nota de ajuste'])->whereNull('period')
            ->update(['period' => DB::raw("DATE_FORMAT(generated_at, '%Y-%m')")]);
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('predecessor_id');
            $table->dropColumn(['period', 'number', 'note_type', 'xml_path', 'dian_message']);
        });
    }
};
