<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** ApplicationResponse de la DIAN y AttachedDocument (contenedor que se envia al cliente). */
    public function up(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('dian_response_path')->nullable();
                $table->string('attached_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['dian_response_path', 'attached_path']));
        }
    }
};
