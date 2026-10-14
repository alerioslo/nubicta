<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('contact_person_id')->nullable()->after('contact_id')->constrained('contact_people')->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->after('seller_id')->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_person_id');
            $table->dropConstrainedForeignId('price_list_id');
        });
    }
};
