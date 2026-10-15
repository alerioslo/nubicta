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
                $t->decimal('synced_balance', 18, 2)->nullable()->after('balance');
            });
        }
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->index(['contact_id', 'due_prefix', 'due_number']);
        });
        Schema::table('receipt_items', function (Blueprint $t) {
            $t->index(['due_prefix', 'due_number']);
        });
    }

    public function down(): void
    {
        foreach (['sales_documents', 'purchase_documents'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('synced_balance'));
        }
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropIndex(['contact_id', 'due_prefix', 'due_number']));
        Schema::table('receipt_items', fn (Blueprint $t) => $t->dropIndex(['due_prefix', 'due_number']));
    }
};
