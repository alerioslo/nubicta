<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->string('email', 160)->nullable()->after('contract_number');
            $table->string('mail_status', 20)->default('Sin enviar')->after('email');
            $table->timestamp('mail_sent_at')->nullable()->after('mail_status');
            $table->string('siigo_guid', 40)->nullable()->after('source');
            $table->index(['company_id', 'type', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'type', 'generated_at']);
            $table->dropColumn(['email', 'mail_status', 'mail_sent_at', 'siigo_guid']);
        });
    }
};
