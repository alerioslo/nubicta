<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('stage', 30);
            $t->date('sent_on');
            $t->string('detail', 255)->nullable();
            $t->timestamps();
            $t->unique(['sales_document_id', 'kind', 'stage']);
        });

        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 100);
            $t->string('token', 64)->unique();
            $t->timestamp('last_used_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('collection_notices');
    }
};
