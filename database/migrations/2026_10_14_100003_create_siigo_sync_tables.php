<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Bitacora de corridas del importador de Siigo */
        Schema::create('siigo_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('entity', 40);
            $table->string('status', 12)->default('running'); // running | done | failed
            $table->json('params')->nullable();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        /* Errores por registro para revisar y reintentar */
        Schema::create('siigo_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('siigo_import_runs')->cascadeOnDelete();
            $table->string('siigo_id')->nullable();
            $table->text('error');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        /* Hash del ultimo JSON importado por entidad, para actualizar solo lo que cambio */
        Schema::create('siigo_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('entity', 40);
            $table->string('siigo_id');
            $table->unsignedBigInteger('local_id');
            $table->string('hash', 40);
            $table->timestamps();
            $table->unique(['company_id', 'entity', 'siigo_id']);
            $table->index(['entity', 'local_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siigo_mappings');
        Schema::dropIfExists('siigo_import_errors');
        Schema::dropIfExists('siigo_import_runs');
    }
};
