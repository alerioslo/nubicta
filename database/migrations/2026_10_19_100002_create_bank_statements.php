<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('bank_account_id')->constrained();
            $t->string('file_name');
            $t->string('format', 40)->nullable();
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->decimal('opening_balance', 18, 2)->nullable();
            $t->decimal('closing_balance', 18, 2)->nullable();
            $t->unsignedInteger('lines_count')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('bank_account_id')->constrained();
            $t->date('date');
            $t->string('description')->nullable();
            $t->string('branch', 120)->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('document', 60)->nullable();
            $t->decimal('amount', 18, 2);
            $t->decimal('balance', 18, 2)->nullable();
            $t->foreignId('ledger_line_id')->nullable()->constrained('ledger_lines')->nullOnDelete();
            $t->string('match_type', 12)->nullable();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $t->char('fingerprint', 40);
            $t->timestamps();
            $t->unique(['bank_account_id', 'fingerprint']);
            $t->index(['bank_account_id', 'date']);
        });

        Schema::table('inventory_movements', fn (Blueprint $t) => $t->index(['company_id', 'document']));
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $t) => $t->dropIndex(['company_id', 'document']));
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
