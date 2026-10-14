<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Documents\RecurringInvoiceGenerator;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'invoices:recurring {--date=}';

    protected $description = 'Elabora las facturas de venta recurrentes que vencen hoy';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        foreach (Company::query()->pluck('id') as $companyId) {
            CurrentCompany::set($companyId);
            $creator = \App\Models\RecurringInvoice::query()->whereNotNull('created_by')->value('created_by')
                ?? User::query()->where('company_id', $companyId)->orderBy('id')->value('id');
            if ($creator) {
                Auth::onceUsingId($creator);
            }
            $r = $generator->due($this->option('date'));
            $this->info("Empresa {$companyId}: {$r['ok']} facturas generadas, {$r['errors']} con error");
        }
        CurrentCompany::set(null);

        return self::SUCCESS;
    }
}
