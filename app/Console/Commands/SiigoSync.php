<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Sincronizacion incremental mientras la empresa siga facturando en Siigo: terceros, documentos recientes y saldos de cartera. */
class SiigoSync extends Command
{
    protected $signature = 'siigo:sync {--dias=2 : dias hacia atras de documentos a releer} {--completo : refresca todas las facturas con saldo}';

    protected $description = 'Trae de Siigo (solo lectura) los cambios recientes y recalcula la cartera y proveedores';

    public function handle(): int
    {
        $desde = now()->subDays(max(1, (int) $this->option('dias')))->toDateString();
        $steps = [
            ['terceros', []],
            ['ventas', ['--desde' => $desde]],
            ['documentos', ['--desde' => $desde]],
            ['cartera', ['--desde' => $desde] + ($this->option('completo') ? ['--completo' => true] : [])],
        ];
        foreach ($steps as [$entity, $options]) {
            if ($this->call('siigo:import', ['entity' => $entity] + $options) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
