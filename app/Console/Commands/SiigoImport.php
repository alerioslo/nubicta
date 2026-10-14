<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Accounting\PucLoader;
use App\Services\Siigo\Importers\ContactsImporter;
use App\Services\Siigo\Importers\OtherDocumentsImporter;
use App\Services\Siigo\Importers\ParametersImporter;
use App\Services\Siigo\Importers\ProductsImporter;
use App\Services\Siigo\Importers\SalesDocumentsImporter;
use App\Services\Siigo\SiigoClient;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SiigoImport extends Command
{
    protected $signature = 'siigo:import
        {entity : puc | parametros | terceros | productos | ventas | documentos | todo}
        {--company= : id de la empresa (por defecto la primera)}
        {--solo= : en documentos: purchases | vouchers | payment-receipts | journals}
        {--desde= : fecha inicial AAAA-MM-DD para documentos}
        {--hasta= : fecha final AAAA-MM-DD para documentos}
        {--forzar : reprocesa aunque el registro no haya cambiado en Siigo}';

    protected $description = 'Importa datos desde Siigo (solo lectura) hacia el ERP';

    /** @var array<string, class-string> */
    private const IMPORTERS = [
        'parametros' => ParametersImporter::class,
        'terceros' => ContactsImporter::class,
        'productos' => ProductsImporter::class,
        'ventas' => SalesDocumentsImporter::class,
        'documentos' => OtherDocumentsImporter::class,
    ];

    public function handle(): int
    {
        $lock = Cache::lock('siigo-import', 4 * 3600);
        if (! $lock->get()) {
            $this->error('Ya hay una importación de Siigo en curso.');

            return self::FAILURE;
        }

        try {
            return $this->import();
        } finally {
            $lock->release();
        }
    }

    private function import(): int
    {
        $company = $this->option('company') ? Company::findOrFail($this->option('company')) : Company::firstOrFail();
        CurrentCompany::set($company->id);
        $entity = $this->argument('entity');
        $params = array_filter(['desde' => $this->option('desde'), 'hasta' => $this->option('hasta'), 'solo' => $this->option('solo')]);

        $entities = $entity === 'todo' ? ['puc', ...array_keys(self::IMPORTERS)] : [$entity];

        foreach ($entities as $e) {
            if ($e === 'puc') {
                $n = app(PucLoader::class)->load($company->id);
                $this->info("PUC: $n cuentas cargadas");

                continue;
            }

            $class = self::IMPORTERS[$e] ?? null;
            if (! $class) {
                $this->error("Entidad desconocida: $e. Opciones: puc, ".implode(', ', array_keys(self::IMPORTERS)).', todo');

                return self::FAILURE;
            }

            $this->info("Importando $e...");
            $run = (new $class(SiigoClient::fromConfig(), $company->id))
                ->onOutput(fn ($m) => $this->line($m))
                ->force((bool) $this->option('forzar'))
                ->run($params)
                ->refresh();
            $this->info("  leidos {$run->fetched}, creados {$run->created}, actualizados {$run->updated}, sin cambios {$run->skipped}, errores {$run->failed}");
        }

        return self::SUCCESS;
    }
}
