<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);
            $t->string('status', 12)->default('pendiente');
            $t->string('label', 120)->nullable();
            $t->string('path', 255)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->json('summary')->nullable();
            $t->text('message')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name', 190)->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
