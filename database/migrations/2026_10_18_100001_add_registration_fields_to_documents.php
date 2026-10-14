<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->foreignId('related_document_id')->nullable()->after('cost_center_id');
            $table->string('related_name', 40)->nullable()->after('related_document_id');
            $table->string('dian_track_id', 120)->nullable()->after('dian_status');
            $table->string('xml_path')->nullable()->after('dian_track_id');
            $table->string('dian_message', 500)->nullable()->after('xml_path');
        });
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->string('dian_track_id', 120)->nullable()->after('dian_status');
            $table->string('xml_path')->nullable()->after('dian_track_id');
            $table->string('dian_message', 500)->nullable()->after('xml_path');
            $table->text('qr_data')->nullable()->after('dian_message');
        });
        Schema::table('dian_transmissions', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_document_id')->nullable()->after('sales_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', fn (Blueprint $t) => $t->dropColumn(['related_document_id', 'related_name', 'dian_track_id', 'xml_path', 'dian_message']));
        Schema::table('sales_documents', fn (Blueprint $t) => $t->dropColumn(['dian_track_id', 'xml_path', 'dian_message', 'qr_data']));
        Schema::table('dian_transmissions', fn (Blueprint $t) => $t->dropColumn('purchase_document_id'));
    }
};
