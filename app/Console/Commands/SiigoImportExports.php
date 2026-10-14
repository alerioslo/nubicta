<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\DianResolution;
use App\Models\Employee;
use App\Models\FixedAssetGroup;
use App\Models\FrequentConcept;
use App\Models\InventoryCosting;
use App\Models\JobPosition;
use App\Models\MeasurementUnit;
use App\Models\PayrollAccountParam;
use App\Models\PayrollConcept;
use App\Models\PeriodLock;
use App\Models\PurchaseDocument;
use App\Models\RadianEvent;
use App\Models\PayrollDocument;
use App\Models\PayrollRun;
use App\Support\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Importa los archivos exportados de Siigo (Excel/JSON) que no estan disponibles en la API. */
class SiigoImportExports extends Command
{
    protected $signature = 'siigo:import-exports {--company=1} {--dir= : carpeta con los archivos (por defecto storage/app/siigo-exports)} {--only= : importar solo estos archivos (separados por coma, ej. nomina_documentos_api.json)}';

    protected $description = 'Importa bancos, empleados, nominas y documentos de nomina exportados de Siigo';

    private string $dir;

    public function handle(): int
    {
        CurrentCompany::set((int) $this->option('company'));
        AuditLog::$enabled = false;
        $this->dir = rtrim($this->option('dir') ?: storage_path('app/siigo-exports'), '/');

        foreach (['settings' => 'config_cargos.json', 'banks' => 'bancos_saldos.xlsx', 'employees' => 'nomina_empleados.xlsx', 'payrollRuns' => 'nomina_nominas.json', 'payrollDocuments' => 'nomina_documentos.json', 'payrollDocumentsApi' => 'nomina_documentos_api.json', 'resolutions' => 'dian_resoluciones.json', 'radian' => 'radian_eventos.json', 'costings' => 'costeo_mensual.json',
            'supportDocuments' => 'compras_documento_soporte.json', 'supplierDebitNotes' => 'compras_nota_debito_nota_de_ajuste.json'] as $method => $file) {
            if ($this->option('only') && ! in_array($file, explode(',', $this->option('only')), true)) {
                continue;
            }
            $path = "{$this->dir}/$file";
            if (! is_file($path)) {
                $this->warn("No existe $file, se omite.");
                continue;
            }
            $this->info("$file: ".$this->$method($path));
        }

        return self::SUCCESS;
    }

    private function sheet(string $path): array
    {
        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    private function banks(string $path): string
    {
        $rows = $this->sheet($path);
        $at = now();
        foreach ($rows as $r) {
            if (is_string($r[0] ?? null) && preg_match('/Procesado en: (\w+) (\d+) (\d{4}) (\d+:\d+)/u', $r[0], $m)) {
                $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
                $at = Carbon::parse(sprintf('%s-%02d-%02d %s', $m[3], $months[mb_strtolower($m[1])] ?? 1, $m[2], $m[4]), 'America/Bogota');
            }
        }
        $n = 0;
        foreach ($rows as $r) {
            $code = trim((string) ($r[0] ?? ''));
            if (! preg_match('/^\d{6,}$/', $code)) {
                continue;
            }
            $account = Account::where('code', $code)->first();
            if (! $account) {
                $this->warn("Cuenta $code no existe en el PUC");
                continue;
            }
            $name = trim((string) $r[1]);
            preg_match('/(\d{6,})\s*$/', $name, $num);
            BankAccount::updateOrCreate(['account_id' => $account->id], [
                'name' => $name,
                'kind' => str_starts_with($code, '1105') ? 'caja' : 'banco',
                'bank_name' => str_starts_with($code, '1105') ? null : strtok($name, ' '),
                'number' => $num[1] ?? null,
                'opening_balance' => round((float) $r[2], 2),
                'opening_at' => $at,
            ]);
            $n++;
        }

        return "$n cuentas, saldo a $at";
    }

    private function contact(string $identification, string $name): Contact
    {
        $contact = Contact::where('identification', $identification)->orderBy('branch_office')->first();
        if (! $contact) {
            $parts = preg_split('/\s+/', trim($name));
            $half = (int) ceil(count($parts) / 2);
            $contact = Contact::create([
                'is_employee' => true, 'person_type' => 'natural', 'identification_type' => '13', 'identification' => $identification,
                'first_name' => implode(' ', array_slice($parts, 0, $half)), 'last_name' => implode(' ', array_slice($parts, $half)),
                'fiscal_responsibilities' => ['R-99-PN'], 'active' => true,
            ]);
        } elseif (! $contact->is_employee) {
            $contact->update(['is_employee' => true]);
        }

        return $contact;
    }

    private function date(?string $v): ?string
    {
        $v = trim((string) $v);

        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m) ? "$m[3]-$m[2]-$m[1]" : null;
    }

    private function employees(string $path): string
    {
        $n = 0;
        foreach ($this->sheet($path) as $r) {
            $id = preg_replace('/\D/', '', (string) ($r[1] ?? ''));
            if ($id === '' || ($r[0] ?? '') === 'Nombre') {
                continue;
            }
            $contact = $this->contact($id, (string) $r[0]);
            $contract = ($r[2] ?? 'No aplica') === 'No aplica' ? null : (string) $r[2];
            $end = $this->date($r[4] ?? null);
            Employee::updateOrCreate(['contact_id' => $contact->id, 'contract_number' => $contract], [
                'start_date' => $this->date($r[3] ?? null),
                'end_date' => $end,
                'salary' => (float) ($r[5] ?? 0),
                'contract_type' => $r[6] ?? null,
                'active' => $end === null || $end >= now()->toDateString(),
            ]);
            $n++;
        }

        return "$n empleados";
    }

    private function money(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], preg_replace('/[^\d.,-]/', '', $v));
    }

    private function payrollRuns(string $path): string
    {
        $n = 0;
        foreach (json_decode(file_get_contents($path), true) as $r) {
            if (! preg_match('#(\d{2}/\d{2}/\d{4}) - (\d{2}/\d{2}/\d{4})#', $r[3] ?? '', $m)) {
                continue;
            }
            PayrollRun::updateOrCreate(
                ['name' => $r[1], 'period_start' => $this->date($m[1]), 'period_end' => $this->date($m[2])],
                ['settlement_type' => $r[2], 'total' => $this->money($r[4]), 'status' => $r[0], 'source' => 'siigo'],
            );
            $n++;
        }

        return "$n nominas";
    }

    private function radian(string $path): string
    {
        $rows = json_decode(file_get_contents($path), true);
        foreach ($rows as $r) {
            $purchase = $r['number'] ? PurchaseDocument::where('supplier_number', $r['number'])->first() : null;
            RadianEvent::updateOrCreate(['cufe' => $r['cufe']], [
                'registered_at' => $r['registered_at'], 'supplier_nit' => $r['nit'], 'document_number' => $r['number'],
                'supplier_name' => $r['supplier'], 'purchase_document_id' => $purchase?->id,
                'reception_status' => $r['reception'], 'goods_status' => $r['goods'], 'acceptance_status' => $r['acceptance'],
                'finished' => $r['finished'], 'external_ref' => $r['ref'],
            ]);
        }

        return count($rows).' eventos';
    }

    /** Filas de una pantalla de configuracion exportada por tools/siigo_config_export.py, con $cols columnas. */
    private function configRows(string $name, int $cols): array
    {
        $path = "{$this->dir}/config_$name.json";

        return is_file($path) ? array_values(array_filter(json_decode(file_get_contents($path), true)['rows'], fn ($r) => count($r) === $cols)) : [];
    }

    private function accountByLabel(?string $label): ?int
    {
        return preg_match('/^(\d+) - /', (string) $label, $m) ? Account::where('code', $m[1])->value('id') : null;
    }

    private function settings(string $path): string
    {
        $out = [];
        $n = 0;
        foreach ($this->configRows('cargos', 2) as $r) {
            JobPosition::updateOrCreate(['name' => $r[0]], ['active' => true]);
            $n++;
        }
        $out[] = "$n cargos";

        $n = 0;
        foreach ($this->configRows('conceptos_nomina', 9) as $r) {
            if (! ctype_digit($r[0])) {
                continue;
            }
            PayrollConcept::updateOrCreate(['code' => (int) $r[0]], [
                'description' => $r[1], 'type' => $r[2], 'electronic_group' => $r[3] ?: null,
                'factor' => $r[4] === '-' ? null : (float) str_replace(',', '.', $r[4]),
                'employee_base' => $r[5] ?: null, 'company_base' => $r[6] ?: null, 'active' => $r[7] === 'Activo',
            ]);
            $n++;
        }
        $out[] = "$n conceptos";

        $n = 0;
        foreach ($this->configRows('parametros_nomina', 5) as $r) {
            if (! ctype_digit($r[0])) {
                continue;
            }
            PayrollAccountParam::updateOrCreate(['concept_code' => (int) $r[0], 'group' => $r[1]], ['concept' => $r[3], 'account_id' => $this->accountByLabel($r[2])]);
            $n++;
        }
        $out[] = "$n parametros contables";

        foreach (['gasto' => 'gastos_frecuentes', 'ingreso' => 'otros_ingresos'] as $kind => $file) {
            $n = 0;
            foreach ($this->configRows($file, 3) as $r) {
                FrequentConcept::updateOrCreate(['kind' => $kind, 'name' => $r[0]], ['account_id' => $this->accountByLabel($r[1]), 'active' => true]);
                $n++;
            }
            $out[] = "$n $file";
        }

        $n = 0;
        foreach ($this->configRows('grupos_activos', 9) as $r) {
            FixedAssetGroup::updateOrCreate(['code' => $r[1]], [
                'name' => $r[2], 'purchase_account_id' => $this->accountByLabel($r[3]), 'depreciation_account_id' => $this->accountByLabel($r[4]),
                'expense_account_id' => $this->accountByLabel($r[5]), 'fiscal_depreciation_account_id' => $this->accountByLabel($r[6]),
                'fiscal_expense_account_id' => $this->accountByLabel($r[7]), 'active' => true,
            ]);
            $n++;
        }
        $out[] = "$n grupos de activos";

        $months = array_flip(['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']);
        $n = 0;
        foreach ($this->configRows('bloqueo_periodos', 4) as $r) {
            if (! isset($months[$r[0]]) || ! $months[$r[0]]) {
                continue;
            }
            PeriodLock::updateOrCreate(['year' => (int) date('Y'), 'month' => $months[$r[0]]], [
                'locked' => $r[3] !== 'Abierto', 'locked_by' => $r[1] ?: null, 'locked_at' => $this->date($r[2]),
            ]);
            $n++;
        }
        $out[] = "$n periodos";

        $n = 0;
        foreach ($this->configRows('unidades_medida', 2) as $r) {
            if ($r[0] === '' || trim($r[1]) === '' || mb_strlen($r[0]) > 4 || $r[0] === 'Para') {
                continue;
            }
            MeasurementUnit::updateOrCreate(['code' => $r[0]], ['name' => trim($r[1]), 'active' => true]);
            $n++;
        }
        $out[] = "$n unidades de medida";

        return implode(', ', $out);
    }

    private function supportDocuments(string $path): string
    {
        return $this->purchaseList($path, 'DS');
    }

    private function supplierDebitNotes(string $path): string
    {
        return $this->purchaseList($path, 'ND');
    }

    /**
     * Filas de Compras > Documentos de compras y gastos filtradas por tipo (la API no entrega DS ni ND):
     * Tipo, Comprobante, Factura proveedor, Fecha, Identificacion, Sucursal, Proveedor, Estado correo, Valor, Moneda, Envio DIAN.
     */
    private function purchaseList(string $path, string $type): string
    {
        $n = 0;
        $detailFile = "{$this->dir}/ds_detalle.json";
        $details = $type === 'DS' && is_file($detailFile) ? json_decode(file_get_contents($detailFile), true) : [];
        $accounts = Account::query()->whereRaw('LENGTH(code) >= 6')->where('code', 'like', '5%')->pluck('id', 'name')
            ->union(FrequentConcept::where('kind', 'gasto')->pluck('account_id', 'name'));
        foreach (json_decode(file_get_contents($path), true) as $r) {
            if (count($r) < 9 || ! preg_match('/^([A-Z]+-\d+)-(\d+)$/', $r[1], $m)) {
                continue;
            }
            $identification = preg_replace('/\D/', '', $r[4]);
            $contact = Contact::where('identification', $identification)->orderBy('branch_office')->first();
            if (! $contact) {
                $parts = preg_split('/\s+/', trim($r[6]));
                $half = (int) ceil(count($parts) / 2);
                $contact = Contact::create([
                    'is_supplier' => true, 'person_type' => 'natural', 'identification_type' => '13', 'identification' => $identification,
                    'first_name' => implode(' ', array_slice($parts, 0, $half)), 'last_name' => implode(' ', array_slice($parts, $half)),
                    'fiscal_responsibilities' => ['R-99-PN'], 'active' => true,
                ]);
            }
            [$supplierPrefix, $supplierNumber] = str_contains($r[2], '-') ? explode('-', $r[2], 2) : [null, $r[2] ?: null];
            $total = (float) str_replace(',', '', $r[8]);
            $dian = $r[10] ?? '';
            $detail = $details[$r[1]] ?? null;
            $withholding = $detail ? array_sum(array_column($detail['retentions'], 'value')) : 0;
            $doc = PurchaseDocument::updateOrCreate(['name' => $r[1]], [
                'source' => 'siigo-web', 'type' => $type, 'number' => (int) $m[2], 'date' => $this->date($r[3]),
                'contact_id' => $contact->id, 'supplier_prefix' => $supplierPrefix, 'supplier_number' => $supplierNumber,
                'currency' => $r[9] ?: 'COP', 'subtotal' => $detail['gross'] ?? $total, 'withholding_total' => $withholding,
                'total' => $total, 'balance' => 0,
                'dian_status' => ['Aceptado' => 'Accepted', 'Rechazado' => 'Rejected'][$dian] ?? ($dian ?: null),
            ]);
            if ($detail) {
                $doc->items()->delete();
                DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->delete();
                foreach ($detail['items'] as $i => $item) {
                    $subtotal = round($item['quantity'] * $item['price'], 2);
                    $doc->items()->create([
                        'position' => $i + 1, 'item_type' => 'Account', 'account_id' => $accounts[$item['description']] ?? null,
                        'description' => $item['description'], 'quantity' => $item['quantity'], 'price' => $item['price'],
                        'subtotal' => $subtotal, 'total' => $subtotal,
                    ]);
                }
                foreach ($detail['retentions'] as $ret) {
                    preg_match('/([\d.,]+)\s*%/', $ret['name'], $pct);
                    DB::table('purchase_document_retentions')->insert([
                        'purchase_document_id' => $doc->id, 'name' => $ret['name'], 'type' => 'ReteFuente',
                        'percentage' => (float) str_replace(',', '.', $pct[1] ?? '0'), 'value' => $ret['value'],
                    ]);
                }
            }
            $n++;
        }

        return "$n documentos $type";
    }

    private function costings(string $path): string
    {
        $rows = json_decode(file_get_contents($path), true);
        foreach ($rows as $r) {
            InventoryCosting::updateOrCreate(['year' => $r['year'], 'month' => $r['month']], ['executed_at' => $r['executed_at'], 'status' => $r['status']]);
        }

        return count($rows).' meses';
    }

    /** Filas de Configuracion > Ventas > Renovacion de numeracion (portal de servicios electronicos de Siigo). */
    private function resolutions(string $path): string
    {
        $n = 0;
        $ymd = fn ($v) => preg_match('#^(\d{4})/(\d{2})/(\d{2})$#', trim((string) $v), $m) ? "$m[1]-$m[2]-$m[3]" : null;
        foreach (json_decode(file_get_contents($path), true) as $r) {
            if (count($r) < 10 || ! preg_match('/^\d{8,}$/', $r[2])) {
                continue;
            }
            DianResolution::updateOrCreate(['resolution_number' => $r[2], 'prefix' => $r[5] ?: null], [
                'kind' => str_contains($r[0], 'soporte') ? 'DS' : 'FV',
                'kind_label' => $r[0],
                'linked_document' => $r[1] ?: null,
                'resolution_date' => $ymd($r[4]),
                'range_from' => (int) $r[6],
                'range_to' => (int) $r[7],
                'valid_from' => $ymd($r[8]),
                'valid_to' => $ymd($r[9]),
            ]);
            $n++;
        }

        return "$n resoluciones";
    }

    /** Filas: (check), fecha, tipo, consecutivo, empleado, identificacion, contrato, neto, estado (icono; en Siigo todos aprobados) */
    private function payrollDocuments(string $path): string
    {
        $n = 0;
        foreach (json_decode(file_get_contents($path), true) as $r) {
            if (count($r) < 8 || ! $this->date($r[1] ?? null)) {
                continue;
            }
            $contact = $this->contact(preg_replace('/\D/', '', $r[5]), $r[4]);
            $employee = Employee::where('contact_id', $contact->id)->first();
            PayrollDocument::updateOrCreate(['consecutive' => $r[3]], [
                'generated_at' => $this->date($r[1]), 'type' => $r[2], 'contact_id' => $contact->id, 'employee_id' => $employee?->id,
                'contract_number' => $r[6] ?: null, 'net' => $this->money($r[7]), 'status' => ($r[8] ?? '') ?: 'Aprobado', 'source' => 'siigo',
            ]);
            $n++;
        }

        return "$n documentos";
    }

    /** Siigo: tipos de devengado de nomina electronica (accruals.type.id) a conceptos del ERP. */
    private const ACCRUALS = [1 => 'sueldo', 2 => 'auxilio_transporte', 11 => 'vacaciones_compensadas', 12 => 'prima'];

    /**
     * Lista y detalle de Nomina > Documentos electronicos de Siigo (FilterDocumentSupportList + GetDocumentSupportByGUID):
     * periodo, CUNE, devengados y deducciones de cada documento, necesarios para notas de ajuste y certificados.
     */
    private function payrollDocumentsApi(string $path): string
    {
        $n = 0;
        foreach (json_decode(file_get_contents($path), true) as $r) {
            $d = $r['detail'] ?? null;
            if (! $d || ! ($r['prefix'] ?? null)) {
                continue;
            }
            $contact = $this->contact((string) $r['identification'], (string) $r['fullName']);
            if ($r['email'] && ! $contact->email) {
                $contact->update(['email' => $r['email']]);
            }
            $employee = Employee::where('contact_id', $contact->id)->first();
            $note = (int) ($r['noteType'] ?? 0);
            $totals = $d['legalMonetaryTotals'] ?? [];
            $doc = PayrollDocument::updateOrCreate(['consecutive' => "{$r['prefix']}-{$r['consecutive']}"], [
                'generated_at' => Carbon::parse($r['date'])->setTimezone('America/Bogota')->toDateString(),
                'period' => sprintf('%04d-%02d', $r['yearPeriod'], $r['monthPeriod']),
                'type' => $note ? 'Nota de ajuste' : 'Soporte de pago', 'note_type' => $note ?: null, 'number' => (int) $r['consecutive'],
                'contact_id' => $contact->id, 'employee_id' => $employee?->id, 'contract_number' => $r['contractNumber'] ?: null,
                'email' => $r['email'] ?: null, 'mail_status' => ($r['sendStatusCode'] ?? 0) ? 'Enviado' : 'Sin enviar',
                'worked_days' => (float) collect($d['accruals'] ?? [])->where('type.id', 1)->sum('quantity'),
                'salary' => (float) ($d['employee']['salary'] ?? 0),
                'earnings' => (float) ($totals['accruedTotalAmount'] ?? 0), 'deductions' => (float) ($totals['deductionTotalAmount'] ?? 0),
                'net' => (float) ($totals['payableAmount'] ?? $r['amount']), 'status' => $r['stateName'] ?: 'Aprobado', 'cune' => $d['cune'] ?? null,
                'source' => 'siigo', 'siigo_guid' => $r['guid'],
            ]);
            $doc->items()->delete();
            foreach ($d['accruals'] ?? [] as $a) {
                $type = (int) $a['type']['id'];
                $concept = $type === 13 ? (str_starts_with(mb_strtolower($a['description']), 'intereses') ? 'intereses_cesantias' : 'cesantias') : (self::ACCRUALS[$type] ?? 'otro_devengado');
                $doc->items()->create(['company_id' => $doc->company_id, 'concept' => $concept, 'label' => $a['description'], 'type' => 'earning',
                    'quantity' => (float) ($a['quantity'] ?? 0), 'rate' => (float) ($a['percentage'] ?? 0), 'value' => (float) $a['amount'] + (float) ($a['assistance'] ?? 0), 'salary_base' => $type === 1]);
            }
            foreach ($d['deductions'] ?? [] as $a) {
                $doc->items()->create(['company_id' => $doc->company_id, 'concept' => [1 => 'salud', 2 => 'pension'][(int) $a['type']['id']] ?? 'otra_deduccion',
                    'label' => $a['description'], 'type' => 'deduction', 'rate' => (float) ($a['percentage'] ?? 0), 'value' => (float) $a['amount']]);
            }
            $n++;
        }

        return "$n documentos electrónicos de nómina";
    }
}
