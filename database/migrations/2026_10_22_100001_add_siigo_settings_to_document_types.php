<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $t) {
            $t->string('title')->nullable()->after('name');
            $t->string('print_format', 20)->default('Básico')->after('footer_text');
            $t->boolean('remission_crossing')->default(false)->after('seller_by_item');
            $t->boolean('purchase_order_ref')->default(false)->after('remission_crossing');
            $t->boolean('delivery_order_ref')->default(false)->after('purchase_order_ref');
            $t->boolean('third_party_income')->default(false)->after('delivery_order_ref');
            $t->boolean('copay')->default(false)->after('third_party_income');
            $t->boolean('aiu')->default(false)->after('copay');
            $t->boolean('show_values')->default(true)->after('aiu');
            $t->string('commercial_sector', 40)->nullable()->after('show_values');
            $t->string('official_book', 40)->nullable()->after('commercial_sector');
            $t->unsignedBigInteger('discount_account_id')->nullable()->after('discount_type');
            $t->unsignedBigInteger('gift_account_id')->nullable()->after('discount_account_id');
            $t->unsignedBigInteger('advance_account_id')->nullable()->after('gift_account_id');
            $t->json('charges')->nullable()->after('advance_account_id');
            $t->string('city', 80)->nullable()->after('print_format');
            $t->string('address')->nullable()->after('city');
            $t->json('phones')->nullable()->after('address');
            $t->string('mail_subject')->nullable()->after('phones');
            $t->text('mail_body')->nullable()->after('mail_subject');
            $t->json('print_columns')->nullable()->after('mail_body');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $t) => $t->dropColumn([
            'title', 'print_format', 'remission_crossing', 'purchase_order_ref', 'delivery_order_ref', 'third_party_income', 'copay', 'aiu',
            'show_values', 'commercial_sector', 'official_book', 'discount_account_id', 'gift_account_id', 'advance_account_id', 'charges',
            'city', 'address', 'phones', 'mail_subject', 'mail_body', 'print_columns',
        ]));
    }
};
