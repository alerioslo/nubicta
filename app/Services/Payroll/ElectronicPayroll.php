<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\DianSetting;
use App\Models\Employee;
use App\Models\PayrollDocument;
use App\Services\Dian\DianService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Documentos electronicos de nomina: un "Soporte de pago" mensual por empleado (DNE-n) que suma las nominas
 * terminadas del periodo, su envio a la DIAN y las notas de ajuste (reemplazar o eliminar).
 */
class ElectronicPayroll
{
    public const PREFIX = 'DNE';

    public const TYPES = ['Soporte de pago', 'Nota de ajuste'];

    /** Configuracion > Prefijos y Provisiones mensuales (payroll_settings.electronic de la empresa). */
    public const DEFAULT_CONFIG = ['prefix' => 'DNE', 'start' => 1, 'note_prefix' => 'DNA', 'note_start' => 1, 'sum_provisions' => false];

    private const FINISHED = ['posted', 'paid', 'Terminada'];

    private const RENAME = ['intereses' => 'intereses_cesantias', 'vacaciones_liquidacion' => 'vacaciones_compensadas'];

    /** Provisiones que se reportan como devengado cuando esta activo "Sumar el valor de las provisiones en los documentos". */
    private const PROVISION_EARNINGS = ['prov_prima' => ['prima', 'Prima de servicios'], 'prov_cesantias' => ['cesantias', 'Cesantías'],
        'prov_intereses' => ['intereses_cesantias', 'Intereses de cesantías'], 'prov_vacaciones' => ['vacaciones', 'Vacaciones']];

    public static function config(?int $companyId = null): array
    {
        $settings = Company::query()->whereKey($companyId ?? \App\Support\CurrentCompany::id())->value('payroll_settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;

        return [...self::DEFAULT_CONFIG, ...($settings['electronic'] ?? [])];
    }

    public static function prefixFor(string $type, ?array $config = null): string
    {
        $config ??= self::config();

        return $type === 'Nota de ajuste' ? $config['note_prefix'] : $config['prefix'];
    }

    /** Una vez la DIAN aprueba un documento con el prefijo, el prefijo y la numeracion inicial ya no se pueden cambiar. */
    public static function locked(string $type, ?array $config = null): bool
    {
        return PayrollDocument::query()->where('type', $type)->where('status', 'Aprobado')
            ->where('consecutive', 'like', self::prefixFor($type, $config).'-%')->exists();
    }

    /** Genera (o regenera si aun no se ha enviado) el documento del periodo para cada empleado con nominas terminadas. */
    public function generate(string $period): int
    {
        [$start, $end] = $this->bounds($period);
        $sources = PayrollDocument::query()->whereNotNull('payroll_run_id')->whereNotIn('type', self::TYPES)
            ->whereHas('run', fn ($q) => $q->whereIn('status', self::FINISHED)->whereBetween('period_end', [$start, $end]))
            ->whereHas('items')->with(['items', 'run'])->get()->groupBy('employee_id');
        if ($sources->isEmpty()) {
            throw new RuntimeException('No hay nóminas en estado terminado para el periodo seleccionado.');
        }

        $count = 0;
        DB::transaction(function () use ($sources, $period, &$count) {
            foreach ($sources as $employeeId => $docs) {
                $current = PayrollDocument::query()->where('employee_id', $employeeId)->where('period', $period)->where('type', 'Soporte de pago')->first();
                if ($current && $current->status === 'Aprobado') {
                    continue;
                }
                $doc = $current ?? new PayrollDocument(['type' => 'Soporte de pago', 'period' => $period, 'employee_id' => $employeeId, 'source' => 'erp']);
                $this->fill($doc, $docs);
                if (! $doc->number) {
                    $doc->number = $this->nextNumber('Soporte de pago');
                    $doc->consecutive = self::prefixFor('Soporte de pago').'-'.$doc->number;
                }
                $doc->status = 'Preliminar';
                $doc->save();
                $this->copyItems($doc, $docs);
                $count++;
            }
        });

        return $count;
    }

    /** Nota de ajuste sobre un documento aprobado: 1 reemplaza con los datos actuales de nomina, 2 elimina. */
    public function adjust(PayrollDocument $approved, int $type): PayrollDocument
    {
        if ($approved->status !== 'Aprobado' || blank($approved->cune)) {
            throw new RuntimeException('Solo se pueden ajustar documentos aprobados por la DIAN.');
        }
        $note = new PayrollDocument([
            'type' => 'Nota de ajuste', 'period' => $approved->period, 'employee_id' => $approved->employee_id, 'contact_id' => $approved->contact_id,
            'predecessor_id' => $approved->id, 'note_type' => $type, 'source' => 'erp', 'status' => 'Preliminar',
            'contract_number' => $approved->contract_number, 'generated_at' => now()->toDateString(),
        ]);
        $note->number = $this->nextNumber('Nota de ajuste');
        $note->consecutive = self::prefixFor('Nota de ajuste').'-'.$note->number;
        DB::transaction(function () use ($note, $approved, $type) {
            if ($type === 1) {
                [$start, $end] = $this->bounds($approved->period);
                $docs = PayrollDocument::query()->where('employee_id', $approved->employee_id)->whereNotNull('payroll_run_id')->whereNotIn('type', self::TYPES)
                    ->whereHas('run', fn ($q) => $q->whereIn('status', self::FINISHED)->whereBetween('period_end', [$start, $end]))->with(['items', 'run'])->get();
                $docs->isEmpty() ? $this->copyFrom($note, $approved) : $this->fill($note, $docs);
                $note->generated_at = now()->toDateString();
                $note->save();
                $docs->isEmpty() ? $this->copyItemsFrom($note, $approved) : $this->copyItems($note, $docs);
            } else {
                $note->save();
            }
        });

        return $note;
    }

    /** @return array{valid:bool, status:string, message:string} */
    public function send(PayrollDocument $doc): array
    {
        if (! in_array($doc->status, ['Preliminar', 'Rechazado'], true)) {
            throw new RuntimeException("El documento {$doc->consecutive} ya fue enviado.");
        }
        if ($doc->note_type === null && PayrollDocument::query()->where('employee_id', $doc->employee_id)->where('period', '<', $doc->period)
            ->where('type', 'Soporte de pago')->whereIn('status', ['Preliminar', 'Rechazado'])->exists()) {
            throw new RuntimeException("Primero envía los documentos de periodos anteriores de este empleado.");
        }
        $dian = DianService::forCurrent();

        return $dian->emitPayroll($doc, $this->map($doc, $dian->settings()));
    }

    /** Documento normalizado para NominaBuilder. */
    public function map(PayrollDocument $doc, DianSetting $settings): array
    {
        $company = Company::query()->findOrFail($doc->company_id);
        $employee = Employee::query()->with('contact')->find($doc->employee_id);
        $contact = $employee?->contact ?? $doc->contact;
        [$softwareId, $pin] = $settings->softwareFor('nomina');
        $production = $settings->environment === 'produccion';
        [$start, $end] = $this->bounds($doc->period ?? $doc->generated_at->format('Y-m'));
        $items = $doc->items()->get();
        $group = fn (string $type) => $items->where('type', $type)->groupBy(fn ($i) => self::RENAME[$i->concept] ?? $i->concept)
            ->map(fn ($g) => ['quantity' => (float) $g->sum('quantity'), 'value' => (float) $g->sum('value'), 'rate' => (float) $g->max('rate'), 'label' => $g->first()->label])->all();
        $names = preg_split('/\s+/', trim((string) ($contact?->first_name ?: $contact?->name))) ?: [''];
        $surnames = preg_split('/\s+/', trim((string) ($contact?->last_name ?? ''))) ?: [''];
        $predecessor = $doc->predecessor_id ? PayrollDocument::query()->find($doc->predecessor_id) : null;
        $time = now()->setTimezone('America/Bogota');
        $hired = $employee?->start_date?->toDateString() ?? $start;
        $prefix = strstr((string) $doc->consecutive, '-', true) ?: self::prefixFor($doc->type);

        return [
            'note_type' => $doc->note_type, 'prefix' => $prefix, 'consecutive' => (string) $doc->number, 'number' => $prefix.$doc->number,
            'issue_date' => $time->toDateString(), 'issue_time' => $time->format('H:i:s').'-05:00', 'environment' => $production ? '1' : '2',
            'notes' => null, 'period_start' => $start, 'period_end' => $end,
            'period_code' => ['Semanal' => '1', 'Decenal' => '2', 'Catorcenal' => '3', 'Quincenal' => '4', 'Mensual' => '5'][$employee?->payment_frequency ?? 'Mensual'] ?? '5',
            'worked_time' => (string) max(1, PayrollLaw::days360($hired, $end)),
            'software' => ['id' => (string) $softwareId, 'pin' => (string) $pin, 'provider_nit' => preg_replace('/\D/', '', (string) $company->nit), 'provider_dv' => (string) $company->dv],
            'employer' => [
                'name' => $company->business_name ?: trim("{$company->first_name} {$company->last_name}"), 'nit' => preg_replace('/\D/', '', (string) $company->nit), 'dv' => (string) $company->dv,
                'department_code' => $company->department_code ?: substr((string) $company->municipality_code, 0, 2), 'municipality_code' => (string) $company->municipality_code,
                'address' => (string) $company->address,
            ],
            'worker' => [
                'type' => $this->workerType((string) $employee?->contributor_type), 'subtype' => $this->workerSubtype((string) $employee?->contributor_subtype), 'document_type' => (string) ($contact?->identification_type ?: '13'),
                'document' => preg_replace('/\D/', '', (string) $contact?->identification), 'first_name' => $names[0], 'other_names' => implode(' ', array_slice($names, 1)) ?: null,
                'last_name' => $surnames[0] ?: '.', 'second_last_name' => implode(' ', array_slice($surnames, 1)) ?: null,
                'department_code' => substr((string) ($contact?->municipality_code ?: $company->municipality_code), 0, 2),
                'municipality_code' => (string) ($contact?->municipality_code ?: $company->municipality_code), 'address' => $contact?->address ?: (string) $company->address,
                'integral' => (bool) $employee?->integral_salary, 'contract_type' => $this->contractType((string) $employee?->contract_type),
                'salary' => (float) ($employee?->salary ?: $doc->salary), 'code' => (string) ($employee?->contract_number ?: $contact?->identification),
                'start_date' => $hired, 'end_date' => $employee?->end_date && $employee->end_date->toDateString() <= $end ? $employee->end_date->toDateString() : null,
            ],
            'payment' => [
                'method' => ['Efectivo' => '10', 'Cheque' => '20', 'Transferencia' => '42', 'Consignación' => '42'][$employee?->payment_method ?? 'Efectivo'] ?? '10',
                'bank' => $employee?->bank_name, 'account_type' => $employee?->bank_account_type, 'account' => $employee?->bank_account,
            ],
            'payment_dates' => [$doc->generated_at?->toDateString() ?? $end],
            'earnings' => $group('earning'), 'deductions' => $group('deduction'),
            'totals' => ['earnings' => (float) $doc->earnings, 'deductions' => (float) $doc->deductions],
            'predecessor' => $predecessor ? ['number' => str_replace('-', '', $predecessor->consecutive), 'cune' => $predecessor->cune,
                'date' => $predecessor->generated_at->toDateString()] : null,
        ];
    }

    private function workerType(string $value): string
    {
        if (preg_match('/^\d{2}$/', $value)) {
            return $value;
        }
        $v = mb_strtolower($value);
        foreach (['servicio dom' => '02', 'madre comunitaria' => '04', 'lectiva' => '12', 'productiva' => '19', 'tiempo parcial' => '51', 'práctica' => '21', 'estudiante' => '20', 'cooperad' => '31', 'pensionad' => '54'] as $needle => $code) {
            if (str_contains($v, $needle)) {
                return $code;
            }
        }

        return '01';
    }

    private function workerSubtype(string $value): string
    {
        if (preg_match('/^\d{2}$/', $value)) {
            return $value;
        }

        return str_contains(mb_strtolower($value), 'pensionado') ? '01' : '00';
    }

    /** @return array{0:string, 1:string} */
    public function bounds(string $period): array
    {
        $d = Carbon::createFromFormat('Y-m-d', "$period-01");

        return [$d->toDateString(), $d->copy()->endOfMonth()->toDateString()];
    }

    private function nextNumber(string $type): int
    {
        $config = self::config();
        $prefix = self::prefixFor($type, $config);
        $max = 0;
        foreach (PayrollDocument::query()->where('type', $type)->where('consecutive', 'like', "$prefix-%")->pluck('consecutive') as $c) {
            if (preg_match('/-(\d+)$/', (string) $c, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return max($max + 1, (int) $config[$type === 'Nota de ajuste' ? 'note_start' : 'start']);
    }

    private function fill(PayrollDocument $doc, $docs): void
    {
        $first = $docs->first();
        $provisions = self::config()['sum_provisions']
            ? (float) $docs->flatMap->items->where('type', 'provision')->whereIn('concept', array_keys(self::PROVISION_EARNINGS))->sum('value') : 0;
        $doc->fill([
            'contact_id' => $first->contact_id, 'contract_number' => $first->contract_number,
            'generated_at' => $docs->max(fn ($d) => $d->run->payment_date?->toDateString() ?? $d->run->period_end->toDateString()),
            'worked_days' => $docs->sum('worked_days'), 'salary' => $first->salary, 'ibc' => $docs->sum('ibc'),
            'earnings' => $docs->sum('earnings') + $provisions, 'deductions' => $docs->sum('deductions'), 'employer_cost' => $docs->sum('employer_cost'),
            'provisions' => $docs->sum('provisions'), 'net' => $docs->sum('net') + $provisions,
        ]);
    }

    private function copyFrom(PayrollDocument $note, PayrollDocument $from): void
    {
        $note->fill($from->only(['worked_days', 'salary', 'ibc', 'earnings', 'deductions', 'employer_cost', 'provisions', 'net']));
    }

    private function copyItems(PayrollDocument $doc, $docs): void
    {
        $doc->items()->delete();
        $items = $docs->flatMap->items->whereIn('type', ['earning', 'deduction'])->values();
        if (self::config()['sum_provisions']) {
            foreach ($docs->flatMap->items->where('type', 'provision')->whereIn('concept', array_keys(self::PROVISION_EARNINGS)) as $p) {
                [$concept, $label] = self::PROVISION_EARNINGS[$p->concept];
                $items->push((clone $p)->forceFill(['concept' => $concept, 'label' => $label, 'type' => 'earning', 'quantity' => 0]));
            }
        }
        foreach ($items->groupBy(fn ($i) => $i->type.'|'.$i->concept) as $g) {
            $i = $g->first();
            $doc->items()->create(['company_id' => $doc->company_id, 'concept' => $i->concept, 'label' => $i->label, 'type' => $i->type,
                'quantity' => $g->sum('quantity'), 'rate' => $i->rate, 'base' => $g->sum('base'), 'value' => $g->sum('value'), 'salary_base' => $i->salary_base]);
        }
    }

    private function copyItemsFrom(PayrollDocument $note, PayrollDocument $from): void
    {
        foreach ($from->items as $i) {
            $note->items()->create($i->only(['company_id', 'concept', 'label', 'type', 'quantity', 'rate', 'base', 'value', 'salary_base']));
        }
    }

    private function contractType(string $type): string
    {
        return match (true) {
            str_contains(mb_strtolower($type), 'fijo') => '1',
            str_contains(mb_strtolower($type), 'obra') => '3',
            str_contains(mb_strtolower($type), 'aprendiz') => '4',
            str_contains(mb_strtolower($type), 'práctica'), str_contains(mb_strtolower($type), 'practica') => '5',
            default => '2',
        };
    }
}
