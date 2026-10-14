<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('name');
            $table->string('type', 20); // IVA, Impoconsumo, Retefuente, ReteIVA, ReteICA, Autorretencion
            $table->decimal('percentage', 8, 4)->default(0);
            $table->boolean('fixed_value')->default(false); // impuesto por valor (ej. impoconsumo bolsas)
            $table->decimal('base_minimum', 18, 2)->default(0); // base minima para retenciones
            $table->string('dian_code', 4)->nullable(); // 01 IVA, 04 INC, 05 ReteIVA, 06 ReteRenta, 07 ReteICA
            $table->foreignId('sales_account_id')->nullable()->constrained('accounts');
            $table->foreignId('purchases_account_id')->nullable()->constrained('accounts');
            $table->foreignId('sales_return_account_id')->nullable()->constrained('accounts');
            $table->foreignId('purchases_return_account_id')->nullable()->constrained('accounts');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('type', 4); // FV NC ND RC CC FC DS NA CE CT(cotizacion) RM(remision) OC
            $table->string('code', 10);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('prefix', 10)->nullable();
            $table->unsignedBigInteger('consecutive')->default(1); // siguiente numero a asignar
            $table->boolean('automatic_number')->default(true);
            $table->string('electronic_type', 30)->default('NoElectronic');
            $table->boolean('cost_center')->default(false);
            $table->boolean('cost_center_mandatory')->default(false);
            $table->boolean('seller_by_item')->default(false);
            $table->boolean('decimals')->default(true);
            $table->string('discount_type', 12)->default('Percentage'); // Percentage | Value
            $table->boolean('reteiva')->default(false);
            $table->boolean('reteica')->default(false);
            $table->boolean('self_withholding')->default(false);
            $table->string('resolution_number', 30)->nullable();
            $table->date('resolution_date')->nullable();
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('technical_key')->nullable();
            $table->text('footer_text')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'type', 'code']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('name');
            $table->string('type', 30); // Cartera (credito cliente) | CarteraProveedor (caja/banco) | Anticipo | Otro
            $table->string('means_code', 4)->default('10'); // medio de pago DIAN: 10 efectivo, 42 consignacion, 47 transferencia, 48 TC, 49 TD, ZZZ
            $table->foreignId('account_id')->nullable()->constrained('accounts');
            $table->boolean('due_date')->default(false); // genera vencimiento (credito)
            $table->json('document_types')->nullable(); // FV, FC, RC, CE...
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('code', 20);
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('responsible')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->unsignedTinyInteger('position');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'position']);
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('identification', 20)->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->decimal('commission_percentage', 6, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('siigo_id')->nullable()->index();
            $table->string('name');
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts');
            $table->foreignId('income_account_id')->nullable()->constrained('accounts');
            $table->foreignId('cost_account_id')->nullable()->constrained('accounts');
            $table->foreignId('return_account_id')->nullable()->constrained('accounts');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['account_groups', 'sellers', 'price_lists', 'warehouses', 'cost_centers', 'payment_methods', 'document_types', 'taxes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
