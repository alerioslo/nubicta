<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained();
            $table->string('identification', 20)->nullable()->after('name');
            $table->string('phone', 30)->nullable()->after('email');
            $table->boolean('is_seller')->default(false)->after('phone');
            $table->boolean('active')->default(true)->after('is_seller');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('siigo_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['identification', 'phone', 'is_seller', 'active', 'last_login_at', 'siigo_id']);
        });
    }
};
