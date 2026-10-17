<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_inbox_documents', function (Blueprint $t) {
            $t->json('product_matches')->nullable()->after('received_from');
        });
    }

    public function down(): void
    {
        Schema::table('dian_inbox_documents', fn (Blueprint $t) => $t->dropColumn('product_matches'));
    }
};
