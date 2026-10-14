<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radian_events', function (Blueprint $table) {
            $table->string('supplier_dv', 2)->nullable()->after('supplier_nit');
            $table->string('payment_means', 2)->nullable()->after('supplier_name');
            $table->date('invoice_date')->nullable()->after('payment_means');
            $table->json('receiver')->nullable()->after('acceptance_status');
            $table->json('events')->nullable()->after('receiver');
            $table->string('claim_code', 2)->nullable()->after('events');
            $table->string('message', 500)->nullable()->after('claim_code');
        });
    }

    public function down(): void
    {
        Schema::table('radian_events', function (Blueprint $table) {
            $table->dropColumn(['supplier_dv', 'payment_means', 'invoice_date', 'receiver', 'events', 'claim_code', 'message']);
        });
    }
};
