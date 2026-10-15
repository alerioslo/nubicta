<?php

namespace App\Console\Commands;

use App\Models\SalesDocument;
use App\Services\Siigo\LineOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Corrige por lotes el orden de las lineas de los comprobantes importados de Siigo, del mas reciente al mas antiguo. */
class SiigoLineOrder extends Command
{
    protected $signature = 'siigo:orden-lineas {--limite=200 : Comprobantes a consultar en esta ejecucion}';

    protected $description = 'Ordena las líneas de los comprobantes importados de Siigo como las muestra Siigo';

    public function handle(): int
    {
        DB::statement("UPDATE sales_documents d SET d.lines_order = 1 WHERE d.source = 'siigo' AND d.lines_order = 0
            AND (SELECT COUNT(*) FROM sales_document_items i WHERE i.sales_document_id = d.id) < 2");

        if (! $fixer = LineOrder::make()) {
            $this->info('Sin credenciales de Siigo: no se consulta el detalle.');

            return self::SUCCESS;
        }
        $docs = SalesDocument::query()->where('source', 'siigo')->where('lines_order', 0)->whereNotNull('siigo_id')
            ->whereIn('type', array_keys(LineOrder::ENDPOINTS))->orderByDesc('date')->orderByDesc('id')
            ->limit((int) $this->option('limite'))->get();

        $stats = ['ok' => 0, 'distinto' => 0, 'error' => 0];
        foreach ($docs as $doc) {
            try {
                $result = $fixer->fix($doc, false);
            } catch (\Throwable $e) {
                $result = null;
                $this->warn("{$doc->name}: ".mb_substr($e->getMessage(), 0, 200));
            }
            $stats[$result === null ? 'error' : ($result ? 'ok' : 'distinto')]++;
            usleep(300_000);
        }
        $left = SalesDocument::query()->where('source', 'siigo')->where('lines_order', 0)->whereIn('type', array_keys(LineOrder::ENDPOINTS))->count();
        $this->info("Ordenados: {$stats['ok']}, sin coincidencia: {$stats['distinto']}, errores: {$stats['error']}. Pendientes: $left");

        return self::SUCCESS;
    }
}
