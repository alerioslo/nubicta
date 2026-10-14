<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Campos de Configuracion > Perfil de la organizacion de Siigo que faltaban. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('show_trade_name')->default(false)->after('trade_name');
            $table->string('contact_name')->nullable()->after('email');
            $table->string('phone2', 30)->nullable()->after('phone');
            $table->string('phone_ext', 10)->nullable()->after('phone2');
            $table->string('ica_rate', 20)->nullable()->after('ciiu');
            $table->boolean('sells_medicines_eps')->default(false)->after('ica_rate');
            $table->boolean('handles_aiu')->default(false)->after('sells_medicines_eps');
            $table->boolean('two_charge_taxes')->default(false)->after('handles_aiu');
            $table->boolean('iva_withholding_agent')->default(false)->after('two_charge_taxes');
            $table->boolean('ad_valorem')->default(false)->after('iva_withholding_agent');
            $table->json('tributes')->nullable()->after('fiscal_responsibilities');
            $table->boolean('foreign_currency')->default(false)->after('currency');
            $table->string('legal_rep_first_name')->nullable();
            $table->string('legal_rep_last_name')->nullable();
            $table->string('legal_rep_identification_type', 4)->nullable();
            $table->string('legal_rep_identification', 20)->nullable();
            $table->boolean('has_partners')->default(false);
            $table->string('siigo_serial', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['show_trade_name', 'contact_name', 'phone2', 'phone_ext', 'ica_rate', 'sells_medicines_eps', 'handles_aiu',
                'two_charge_taxes', 'iva_withholding_agent', 'ad_valorem', 'tributes', 'foreign_currency', 'legal_rep_first_name',
                'legal_rep_last_name', 'legal_rep_identification_type', 'legal_rep_identification', 'has_partners', 'siigo_serial']);
        });
    }
};
