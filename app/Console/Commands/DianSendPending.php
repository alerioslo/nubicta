<?php

namespace App\Console\Commands;

use App\Models\DocumentType;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Services\Dian\DianService;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/** Envia a la DIAN los documentos electronicos del ERP que quedaron pendientes (borrador o rechazados). */
class DianSendPending extends Command
{
    protected $signature = 'dian:send-pending {--company=1} {--limit=500}';

    protected $description = 'Envía a la DIAN las facturas, notas y documentos soporte pendientes del ERP';

    public function handle(): int
    {
        CurrentCompany::set((int) $this->option('company'));
        $dian = DianService::forCurrent();
        $electronic = DocumentType::query()->where('electronic_type', '!=', 'NoElectronic')->pluck('id');
        $docs = collect([SalesDocument::class, PurchaseDocument::class])->flatMap(fn ($m) => $m::query()
            ->where('source', 'erp')->where('status', 'issued')->whereIn('document_type_id', $electronic)
            ->whereIn('dian_status', ['Draft', 'Rejected', 'Sent', 'Pending'])->orderBy('date')->orderBy('number')
            ->limit((int) $this->option('limit'))->get());

        $ok = 0;
        foreach ($docs as $doc) {
            try {
                $r = $dian->emit($doc);
                $r['valid'] && $ok++;
                $this->line("{$doc->name}: {$r['status']} - {$r['message']}");
            } catch (\Throwable $e) {
                $this->error("{$doc->name}: {$e->getMessage()}");
            }
        }
        $this->info("Enviados {$docs->count()}, aceptados $ok.");

        return self::SUCCESS;
    }
}
