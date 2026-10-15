<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $t) {
            $t->string('format', 10)->nullable()->after('title');
            $t->string('url', 2000)->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', fn (Blueprint $t) => $t->dropColumn(['format', 'url']));
    }
};
