<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('url', 500);
            $t->json('events');
            $t->string('secret', 64);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('last_status')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedInteger('failures')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_webhooks');
    }
};
