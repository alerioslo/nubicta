<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Sales\CollectionAutomation;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunCollections extends Command
{
    protected $signature = 'collections:run {--date=}';

    protected $description = 'Envía los recordatorios de pago y genera las tareas de cobranza del día';

    public function handle(CollectionAutomation $automation): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : null;
        foreach (Company::query()->pluck('id') as $companyId) {
            CurrentCompany::set($companyId);
            $r = $automation->reminders($date);
            $t = $automation->tasks($date);
            $this->info("Empresa {$companyId}: {$r['sent']} recordatorios enviados, {$r['skipped']} sin enviar, {$t['created']} tareas de cobranza");
            foreach ($r['errors'] as $error) {
                $this->warn("  $error");
            }
        }
        CurrentCompany::set(null);

        return self::SUCCESS;
    }
}
