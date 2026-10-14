<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('related_applied', 18, 2)->default(0)->after('related_name');
                $t->dateTime('annulled_at')->nullable()->after('status');
                $t->unsignedBigInteger('annulled_by')->nullable()->after('annulled_at');
            });
        }
        foreach (['receipts', 'journal_entries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dateTime('annulled_at')->nullable()->after('status');
                $t->unsignedBigInteger('annulled_by')->nullable()->after('annulled_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['related_applied', 'annulled_at', 'annulled_by']));
        }
        foreach (['receipts', 'journal_entries'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['annulled_at', 'annulled_by']));
        }
    }
};
