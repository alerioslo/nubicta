<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DianSetting;
use App\Models\DocumentType;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Support\ColombianNit;
use Illuminate\Support\Facades\DB;

/** Convierte facturas, notas y documentos soporte del ERP al arreglo que consume UblBuilder. */
class DocumentMapper
{
    /** Clave tecnica publica de la numeracion de pruebas SETP de habilitacion. */
    public const TEST_TECHNICAL_KEY = 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c';

    public function __construct(private readonly DianSetting $settings, private readonly Company $company) {}

    public function sales(SalesDocument $doc): array
    {
        $type = DocumentType::query()->find($doc->document_type_id);
        $kind = $doc->type;
        $items = DB::table('sales_document_items')->where('sales_document_id', $doc->id)->orderBy('position')->get();
        $taxes = DB::table('sales_document_item_taxes')->whereIn('sales_document_item_id', $items->pluck('id'))->get()->groupBy('sales_document_item_id');
        $related = in_array($doc->type, ['NC', 'ND'], true) && $doc->related_document_id ? SalesDocument::query()->find($doc->related_document_id) : null;

        $supplier = ['registration_prefix' => ($type?->prefix ?? '') ?: null] + $this->companyParty();
        $d = $this->document($kind, $doc, $type, 'fe', $supplier, $this->contactParty($doc->contact), $items, $taxes, $related);
        $summary = DB::table('sales_document_retentions')->where('sales_document_id', $doc->id)->get();

        return $this->withholdings($d, $summary);
    }

    /**
     * WithholdingTaxTotal (informativo para la DIAN; no altera el PayableAmount): retenciones del documento y de cada linea,
     * agrupadas por tributo y tarifa. La autorretencion no es una retencion practicada por el adquirente.
     */
    private function withholdings(array $d, $summary): array
    {
        $rows = collect($d['line_withholdings'] ?? []);
        foreach ($summary as $w) {
            if ($code = UblBuilder::WITHHOLDING_CODES[$w->type] ?? null) {
                $base = (float) ($w->base ?? 0) ?: ($w->type === 'ReteIVA' ? $d['totals']['tax_inclusive'] - $d['totals']['line_extension'] : $d['totals']['line_extension']);
                $rows->push(['code' => $code[0], 'name' => $code[1], 'percent' => Tax::percent($w->type, $w->percentage), 'base' => $base, 'amount' => (float) $w->value]);
            }
        }
        $d['withholdings'] = $rows->filter(fn ($r) => $r['amount'] > 0)->groupBy(fn ($r) => $r['code'].'|'.$r['percent'])
            ->map(fn ($g) => ['code' => $g[0]['code'], 'name' => $g[0]['name'], 'percent' => $g[0]['percent'], 'base' => round($g->sum('base'), 2), 'amount' => round($g->sum('amount'), 2)])
            ->values()->all();
        unset($d['line_withholdings']);

        return $d;
    }

    public function purchase(PurchaseDocument $doc): array
    {
        $type = DocumentType::query()->find($doc->document_type_id);
        $items = DB::table('purchase_document_items')->where('purchase_document_id', $doc->id)->orderBy('position')->get();
        $taxes = DB::table('purchase_document_item_taxes')->whereIn('purchase_document_item_id', $items->pluck('id'))->get()->groupBy('purchase_document_item_id');
        $related = $doc->related_document_id ? PurchaseDocument::query()->find($doc->related_document_id) : null;
        $withholdings = DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->get();

        $supplier = $this->contactParty($doc->contact);
        if ($supplier['id_type'] === '13') {
            // DSAJ25a: el documento soporte solo admite al proveedor con NIT; el de una persona natural es su cedula + DV.
            $supplier['id_type'] = '31';
            $supplier['dv'] = ColombianNit::resolveVerificationDigit($supplier['id'], $supplier['dv']);
        }
        $d = $this->document($doc->type, $doc, $type, 'ds', $supplier, $this->companyParty(), $items, $taxes, $related, 'purchase_document_item_id');

        return $this->withholdings($d, $withholdings);
    }

    private function document(string $kind, SalesDocument|PurchaseDocument $doc, ?DocumentType $type, string $scope, array $supplier, array $customer, $items, $taxes, $related, string $fk = 'sales_document_item_id'): array
    {
        [$softwareId, $pin] = $this->settings->softwareFor($scope);
        $production = $this->settings->environment === 'produccion';
        $lines = [];
        $docTaxes = [];
        $lineWithholdings = [];
        $units = DB::table('products')->whereIn('id', collect($items)->pluck('product_id')->filter()->unique())->pluck('unit_code', 'id');
        foreach ($items as $it) {
            $base = (float) $it->subtotal;
            $gross = (float) $it->quantity * (float) $it->price - (float) $it->discount;
            $factor = $gross > 0 ? $base / $gross : 1;
            $lineTaxes = [];
            foreach ($taxes->get($it->id, collect()) as $t) {
                if ($code = UblBuilder::TAX_CODES[$t->type] ?? null) {
                    $lineTaxes[] = $docTaxes[] = ['code' => $code[0], 'name' => $code[1], 'percent' => (float) $t->percentage, 'base' => $base, 'amount' => (float) $t->value];
                } elseif ($code = UblBuilder::WITHHOLDING_CODES[$t->type] ?? null) {
                    $lineWithholdings[] = ['code' => $code[0], 'name' => $code[1], 'percent' => Tax::percent($t->type, $t->percentage), 'base' => $t->type === 'ReteIVA' ? (float) $it->tax_total : $base, 'amount' => (float) $t->value];
                }
            }
            $lines[] = [
                'code' => $it->code ?: 'ITEM'.$it->position, 'description' => $it->description, 'quantity' => (float) $it->quantity,
                'price' => round((float) $it->price * $factor, 2), 'discount' => round((float) $it->discount * $factor, 2), 'base' => $base, 'taxes' => $lineTaxes,
                'unit' => ($it->product_id ?? null) ? ($units[$it->product_id] ?: '94') : '94',
            ];
        }
        $docTaxes = collect($docTaxes)->groupBy(fn ($t) => $t['code'].'|'.$t['percent'])
            ->map(fn ($g) => ['code' => $g[0]['code'], 'name' => $g[0]['name'], 'percent' => $g[0]['percent'], 'base' => round($g->sum('base'), 2), 'amount' => round($g->sum('amount'), 2)])
            ->values()->all();
        $lineExtension = round(array_sum(array_column($lines, 'base')), 2);
        $taxAmount = round(array_sum(array_column($docTaxes, 'amount')), 2);
        $time = ($doc->created_at ?? now())->copy()->setTimezone('America/Bogota');
        $credit = (float) $doc->balance > 0;

        return [
            'kind' => $kind,
            'number' => ($type?->prefix ?? '').$doc->number,
            'issue_date' => $doc->date->toDateString(),
            'issue_time' => $time->format('H:i:s').'-05:00',
            'due_date' => ($doc->due_date ?? $doc->date)->toDateString(),
            'environment' => $production ? '1' : '2',
            'currency' => $doc->currency ?: 'COP',
            'notes' => $doc->observations,
            'technical_key' => $production ? ($type?->technical_key ?? '') : ($type?->technical_key ?: self::TEST_TECHNICAL_KEY),
            'resolution' => [
                'number' => $type?->resolution_number ?? '', 'valid_from' => $type?->valid_from?->toDateString() ?? '', 'valid_to' => $type?->valid_to?->toDateString() ?? '',
                'prefix' => $type?->prefix ?? '', 'from' => $type?->range_from ?? 1, 'to' => $type?->range_to ?? 99999999,
            ],
            'software' => ['id' => (string) $softwareId, 'pin' => (string) $pin, 'provider_nit' => $this->company->nit, 'provider_dv' => (string) $this->company->dv],
            'supplier' => $supplier,
            'customer' => $customer,
            'payment' => ['id' => $credit ? '2' : '1', 'code' => $credit ? 'ZZZ' : '10', 'due_date' => ($doc->due_date ?? $doc->date)->toDateString()],
            'lines' => $lines,
            'taxes' => $docTaxes,
            'withholdings' => [],
            'line_withholdings' => $lineWithholdings,
            'totals' => [
                'line_extension' => $lineExtension,
                'tax_exclusive' => round(collect($lines)->filter(fn ($l) => $l['taxes'])->sum('base'), 2),
                'tax_inclusive' => round($lineExtension + $taxAmount, 2),
                'payable' => round($lineExtension + $taxAmount, 2),
            ],
            'reference' => $related ? [
                'number' => (DocumentType::query()->whereKey($related->document_type_id)->value('prefix') ?? '').$related->number,
                'key' => $related->cufe ?? $related->cuds ?? '', 'date' => $related->date->toDateString(),
            ] : null,
            'discrepancy' => in_array($kind, ['NC', 'ND'], true)
                ? CorrectionConcepts::for($kind, $doc->reason)
                : ['code' => '2', 'description' => $doc->observations ?: 'Ajuste de valor'],
        ];
    }

    public function companyParty(): array
    {
        $c = $this->company;
        $place = $this->place($c->municipality_code);

        return [
            'type' => $c->person_type === 'juridica' ? '1' : '2', 'id_type' => (string) ($c->identification_type ?: '31'),
            'id' => preg_replace('/\D/', '', (string) $c->nit), 'dv' => (string) $c->dv, 'name' => $c->business_name,
            'address' => $c->address, 'municipality_code' => $c->municipality_code, 'city' => $place['city'], 'department' => $place['department'],
            'department_code' => $c->department_code ?: substr((string) $c->municipality_code, 0, 2), 'postal' => $c->postal_code,
            'email' => $c->email, 'phone' => $c->phone,
            'tax_level' => implode(';', $this->list($c->fiscal_responsibilities) ?: ['R-99-PN']),
            'tax_scheme' => in_array('01', $this->list($c->tributes), true) ? ['01', 'IVA'] : ['ZZ', 'No aplica'],
            'registration_prefix' => null,
        ];
    }

    public function contactParty(?Contact $c): array
    {
        $place = $this->place($c?->municipality_code ?: $this->company->municipality_code);
        $responsibilities = $this->list($c?->fiscal_responsibilities);

        return [
            'type' => $c?->person_type === 'juridica' ? '1' : '2', 'id_type' => (string) ($c?->identification_type ?: '13'),
            'id' => preg_replace('/\D/', '', (string) ($c?->identification ?? '222222222222')), 'dv' => (string) ($c?->dv ?? ''),
            'name' => $c?->name ?: 'Consumidor final', 'address' => $c?->address ?: 'Sin dirección',
            'municipality_code' => $c?->municipality_code ?: $this->company->municipality_code, 'city' => $place['city'], 'department' => $place['department'],
            'department_code' => $c?->department_code ?: substr((string) ($c?->municipality_code ?: $this->company->municipality_code), 0, 2),
            'postal' => $c?->postal_code, 'email' => $c?->email, 'phone' => $c?->mobile ?: $c?->phone,
            'tax_level' => implode(';', $responsibilities ?: ['R-99-PN']),
            'tax_scheme' => $c?->vat_responsible ? ['01', 'IVA'] : ['ZZ', 'No aplica'],
            'registration_prefix' => null,
        ];
    }

    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?? [$value];
        }

        return array_values(array_filter((array) $value));
    }

    /** @return array{city:string, department:string} */
    private function place(?string $code): array
    {
        $row = DB::table('municipalities')->leftJoin('departments', 'departments.code', '=', 'municipalities.department_code')
            ->where('municipalities.code', $code)->first(['municipalities.name as city', 'departments.name as department']);

        return ['city' => $row->city ?? '', 'department' => $row->department ?? ''];
    }
}
