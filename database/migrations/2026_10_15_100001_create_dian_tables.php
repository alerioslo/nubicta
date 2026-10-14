<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dian_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained();
            $table->string('environment', 12)->default('habilitacion'); // habilitacion | produccion
            $table->boolean('simulated')->default(true);
            $table->string('software_id')->nullable();
            $table->text('software_pin')->nullable();
            $table->string('test_set_id')->nullable();
            $table->string('payroll_software_id')->nullable();
            $table->text('payroll_software_pin')->nullable();
            $table->string('payroll_test_set_id')->nullable();
            $table->string('support_software_id')->nullable();
            $table->text('support_software_pin')->nullable();
            $table->string('support_test_set_id')->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('certificate_password')->nullable();
            $table->json('certificate_meta')->nullable();
            $table->string('reception_email')->nullable();
            $table->string('technical_contact')->nullable();
            $table->json('habilitation')->nullable(); // {fe:{status,sent,accepted}, nomina:{...}, ds:{...}}
            $table->timestamp('production_since')->nullable();
            $table->timestamps();
        });

        Schema::create('dian_transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('operation', 40); // SendTestSetAsync, SendBillSync, GetStatusZip, GetStatus, GetNumberingRange
            $table->string('scope', 10)->default('fe'); // fe | nomina | ds
            $table->string('document_kind', 4)->nullable(); // FV NC ND DS NA NE NA
            $table->string('document_number', 40)->nullable();
            $table->foreignId('sales_document_id')->nullable()->constrained();
            $table->string('environment', 12);
            $table->boolean('simulated')->default(true);
            $table->string('track_id', 120)->nullable();
            $table->string('cufe', 120)->nullable();
            $table->boolean('is_valid')->nullable();
            $table->string('status_code', 10)->nullable();
            $table->string('status_message', 500)->nullable();
            $table->json('errors')->nullable();
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dian_transmissions');
        Schema::dropIfExists('dian_settings');
    }
};
