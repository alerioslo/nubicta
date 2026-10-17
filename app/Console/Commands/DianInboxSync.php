<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Mail\CompanyMailer;
use App\Services\Purchases\PurchaseInbox;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/** Bandeja DIAN: descarga del buzon de recepcion (IMAP) las facturas nuevas de proveedores, igual que el boton "Sincronizar". */
class DianInboxSync extends Command
{
    protected $signature = 'dian:inbox-sync {--company= : Solo esta empresa}';

    protected $description = 'Sincroniza la Bandeja DIAN con el buzón de recepción de facturas de proveedores';

    public function handle(PurchaseInbox $inbox): int
    {
        $companies = Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->pluck('id');
        foreach ($companies as $id) {
            CurrentCompany::set((int) $id);
            $s = CompanyMailer::settings($id);
            if (blank($s['imap_host'] ?? null) || blank($s['imap_username'] ?? null) || blank($s['imap_password'] ?? null)) {
                continue;
            }
            $log = $inbox->sync();
            $this->line("Empresa $id: {$log->status}, {$log->received} documentos nuevos".($log->observations ? " ({$log->observations})" : ''));
        }

        return self::SUCCESS;
    }
}
