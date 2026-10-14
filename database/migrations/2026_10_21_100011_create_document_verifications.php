<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('kind', 20);
            $t->unsignedBigInteger('document_id');
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at');
            $t->unique(['kind', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
    }
};
