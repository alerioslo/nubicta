<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Accounting\TaxCatalog;
use Illuminate\Database\Seeder;

class TaxSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Company::query()->pluck('id') as $companyId) {
            $r = app(TaxCatalog::class)->install($companyId);
            $this->command?->info("Empresa $companyId: {$r['created']} impuestos creados, {$r['updated']} actualizados");
        }
    }
}
