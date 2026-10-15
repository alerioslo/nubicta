<?php

namespace App\Http\Controllers\Settings;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Services\Documents\JournalRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Importación de facturas de venta, documentos de ingreso y comprobantes contables: la plantilla de Siigo en tres pasos. */
class VoucherImportController extends Controller
{
    public const HEAD = ['Tipo de comprobante', 'Consecutivo comprobante', 'Fecha de elaboración ', 'Sigla moneda', 'Tasa de cambio', 'Código cuenta contable',
        'Identificación tercero', 'Sucursal', 'Código producto', 'Código de bodega', 'Acción', 'Cantidad producto', 'Prefijo', 'Consecutivo', 'No. cuota',
        'Fecha vencimiento', 'Código impuesto', 'Código grupo activo fijo', 'Código activo fijo', 'Descripción', 'Código centro/subcentro de costos', 'Débito',
        'Crédito', 'Observaciones', 'Base gravable libro compras/ventas  ', 'Base exenta libro compras/ventas', 'Mes de cierre'];

    private const KINDS = ['fv' => ['FV', 'Facturas de venta / Documentos de ingreso'], 'cc' => ['CC', 'Comprobante contable']];

    private const MAX = 500;

    private const URL = '/configuracion/importacion-comprobantes';

    public function show(Request $request): Response
    {
        $kind = array_key_exists($request->input('tipo'), self::KINDS) ? $request->input('tipo') : null;
        $preview = $request->input('paso') === '3' ? session('voucher_import') : null;

        return Inertia::render('Settings/VoucherImport', [
            'step' => $preview ? 3 : ($kind ? 2 : 1),
            'kind' => $preview['kind'] ?? $kind ?? 'fv',
            'kinds' => collect(self::KINDS)->map(fn ($k) => $k[1]),
            'preview' => $preview,
            'types' => DocumentType::query()->where('type', self::KINDS[$preview['kind'] ?? $kind ?? 'fv'][0])->where('active', true)
                ->when(($preview['kind'] ?? $kind) === 'fv', fn ($q) => $q->where('electronic_type', 'NoElectronic'))
                ->orderByRaw('code + 0')->get(['code', 'name']),
        ]);
    }

    public function template()
    {
        $type = DocumentType::query()->where('type', 'CC')->orderByRaw('code + 0')->first();
        $example = [$type?->code ?? '1', 1, now()->format('d/m/Y'), '', '', '11050501', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Ejemplo', '', 100000, 0, 'Comprobante de ejemplo', '', '', ''];

        return ArrayExport::download('Modelo de importacion de comprobantes contables.xlsx', self::HEAD,
            [$example, array_replace($example, [5 => '41359501', 21 => 0, 22 => 100000])], 'Datos');
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'kind' => ['required', 'in:'.implode(',', array_keys(self::KINDS))],
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,xlsm'],
        ], ['file.required' => 'Seleccione el archivo de excel para importar', 'file.extensions' => 'El archivo debe ser de Excel (xlsx, xls o xlsm)']);
        $kind = $request->input('kind');
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        array_shift($sheet);
        $sheet = array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== ''));
        if (! $sheet) {
            throw ValidationException::withMessages(['file' => 'El archivo no tiene registros']);
        }
        if (count($sheet) > self::MAX) {
            throw ValidationException::withMessages(['file' => 'Se permiten hasta '.self::MAX.' registros por archivo']);
        }

        $types = DocumentType::query()->where('type', self::KINDS[$kind][0])->where('active', true)
            ->when($kind === 'fv', fn ($q) => $q->where('electronic_type', 'NoElectronic'))->get()->keyBy('code');
        $accounts = Account::query()->get(['id', 'code', 'name', 'transactional', 'requires_third_party', 'due_type', 'active'])->keyBy('code');
        $contacts = Contact::query()->pluck('id', 'identification');
        $centers = CostCenter::query()->pluck('id', 'code');

        $rows = [];
        foreach ($sheet as $i => $c) {
            $c = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_pad($c, 27, null));
            $errors = [];
            $typeCode = preg_replace('/^[A-Z]+-/', '', (string) $c[0]);
            if (! isset($types[$typeCode])) {
                $errors[] = $kind === 'fv' ? "No existe el tipo de documento de ingreso \"{$c[0]}\" (solo se importan tipos no electrónicos)" : "No existe el tipo de comprobante \"{$c[0]}\"";
            }
            if ((string) $c[1] === '' || ! ctype_digit((string) $c[1])) {
                $errors[] = 'El consecutivo del comprobante es obligatorio y numérico';
            }
            $date = $this->date($c[2]);
            if (! $date) {
                $errors[] = 'La fecha de elaboración no es válida';
            }
            $account = $accounts[(string) $c[5]] ?? null;
            if (! $account) {
                $errors[] = "No existe la cuenta contable \"{$c[5]}\"";
            } elseif (! $account->transactional || ! $account->active) {
                $errors[] = "La cuenta {$c[5]} no es de movimiento o está inactiva";
            }
            $contactId = (string) $c[6] !== '' ? ($contacts[(string) $c[6]] ?? null) : null;
            if ((string) $c[6] !== '' && ! $contactId) {
                $errors[] = "No existe el tercero con identificación {$c[6]}";
            }
            if ($account && ($account->requires_third_party || $account->due_type) && ! $contactId) {
                $errors[] = "La cuenta {$c[5]} requiere tercero";
            }
            $center = (string) $c[20] !== '' ? ($centers[(string) $c[20]] ?? null) : null;
            if ((string) $c[20] !== '' && ! $center) {
                $errors[] = "No existe el centro de costo \"{$c[20]}\"";
            }
            $debit = $this->number($c[21]);
            $credit = $this->number($c[22]);
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || $debit + $credit <= 0) {
                $errors[] = 'Cada línea lleva un valor en débito o en crédito (no ambos)';
            }
            $due = $this->date($c[15]);
            if ((string) $c[15] !== '' && ! $due) {
                $errors[] = 'La fecha de vencimiento no es válida';
            }
            $rows[] = [
                'row' => $i + 2, 'type' => $typeCode, 'number' => (string) $c[1], 'date' => $date, 'account' => (string) $c[5],
                'account_name' => $account->name ?? '', 'account_id' => $account->id ?? null, 'due_type' => $account->due_type ?? null,
                'identification' => (string) $c[6], 'contact_id' => $contactId, 'prefix' => (string) $c[12], 'due_number' => (string) $c[13],
                'quote' => (int) ($c[14] ?: 1), 'due_date' => $due, 'description' => mb_substr((string) $c[19], 0, 255), 'cost_center_id' => $center,
                'debit' => (float) $debit, 'credit' => (float) $credit, 'observations' => mb_substr((string) $c[23], 0, 500), 'errors' => implode('. ', $errors),
            ];
        }

        foreach (collect($rows)->groupBy(fn ($r) => $r['type'].'|'.$r['number']) as $lines) {
            $diff = round($lines->sum('debit') - $lines->sum('credit'), 2);
            $dates = $lines->pluck('date')->filter()->unique();
            $message = $diff != 0 ? 'El comprobante '.$lines[0]['type'].'-'.$lines[0]['number'].' no está cuadrado (diferencia '.number_format($diff, 2, ',', '.').')'
                : ($dates->count() > 1 ? 'Las líneas del comprobante tienen fechas diferentes' : null);
            if ($message) {
                foreach ($lines as $l) {
                    $rows[array_search($l['row'], array_column($rows, 'row'))]['errors'] = trim($l['errors'].'. '.$message, '. ');
                }
            }
        }
        session(['voucher_import' => ['kind' => $kind, 'file' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]]);

        return redirect(self::URL.'?paso=3');
    }

    public function confirm(JournalRegistrar $journals): RedirectResponse
    {
        $data = session('voucher_import') ?? throw ValidationException::withMessages(['file' => 'Carga de nuevo el archivo']);
        $rows = collect($data['rows']);
        if ($rows->contains(fn ($r) => $r['errors'] !== '')) {
            throw ValidationException::withMessages(['file' => 'Corrige los errores del archivo y vuelve a cargarlo']);
        }
        $types = DocumentType::query()->where('type', self::KINDS[$data['kind']][0])->get()->keyBy('code');
        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id')->filter()->unique())->get()->keyBy('id');
        $names = DB::transaction(function () use ($rows, $types, $contacts, $journals) {
            $names = [];
            foreach ($rows->groupBy(fn ($r) => $r['type'].'|'.$r['number']) as $lines) {
                $first = $lines[0];
                $entry = $journals->store($types[$first['type']], ['date' => $first['date'], 'observations' => $first['observations'] ?: 'Importación de comprobantes'],
                    $lines->map(fn ($l) => [
                        'account_id' => $l['account_id'], 'contact' => $contacts->get($l['contact_id']), 'description' => $l['description'] ?: null,
                        'debit' => $l['debit'], 'credit' => $l['credit'], 'cost_center_id' => $l['cost_center_id'],
                    ] + ($l['due_type'] ? ['due_raw' => [
                        'prefix' => $l['prefix'] ?: ($first['type'] ? $types[$first['type']]->type : 'CC'),
                        'number' => (int) ($l['due_number'] ?: $first['number']), 'quote' => max(1, $l['quote']), 'due_date' => $l['due_date'] ?? $l['date'],
                    ]] : []))->all(), 'import');
                $names[] = $entry->name;
            }

            return $names;
        });
        session()->forget('voucher_import');

        return redirect(self::URL)->with('success', 'Importación terminada: '.count($names).' comprobantes creados ('.implode(', ', array_slice($names, 0, 10)).(count($names) > 10 ? '…' : '').').');
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->toDateString();
        }
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y'] as $format) {
            $d = \DateTime::createFromFormat("!$format", (string) $v);
            if ($d && $d->format($format) === (string) $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function number(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $clean = str_replace(['$', ' '], '', (string) $v);
        $clean = str_contains($clean, ',') ? str_replace(['.', ','], ['', '.'], $clean) : $clean;

        return is_numeric($clean) ? (float) $clean : null;
    }
}
