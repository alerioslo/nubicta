<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $t) {
            $t->string('mail_status', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', fn (Blueprint $t) => $t->dropColumn('mail_status'));
    }
};
