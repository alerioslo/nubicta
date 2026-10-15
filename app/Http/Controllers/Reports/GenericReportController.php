<?php

namespace App\Http\Controllers\Reports;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Seller;
use App\Reports\ReportRegistry;
use App\Services\Reports\TablePdf;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Pantalla unica para los informes de ReportRegistry: filtros, tabla, totales y descarga a Excel o PDF. */
class GenericReportController extends Controller
{
    /** Etiquetas de los filtros, como las muestra Siigo. */
    public const FILTER_LABELS = [
        'range' => 'Fecha de elaboración', 'cutoff' => 'Fecha de corte', 'year' => 'Año', 'period' => 'Periodo', 'contact' => 'Cliente', 'product' => 'Producto',
        'seller' => 'Vendedor', 'line' => 'Línea de producto', 'group' => 'Grupo inventario', 'cost_center' => 'Centro de costo',
        'module' => 'Tipo de cartera', 'level' => 'Nivel de cuenta', 'account' => 'Cuenta contable', 'doctype' => 'Tipo de comprobante',
        'dian' => 'Estado DIAN', 'q' => 'Buscar', 'nc' => 'Incluye nota crédito', 'pos' => 'Solo ventas pos', 'tax' => 'Incluye impuesto', 'payment' => 'Forma de pago',
        'creator' => 'Creado por', 'currency' => 'Moneda', 'status' => 'Estado', 'warehouse' => 'Bodega', 'kind' => 'Tipo transacción',
    ];

    public function show(Request $request, string $slug)
    {
        $def = ReportRegistry::all()[$slug] ?? abort(404);
        $f = $this->filters($request);
        foreach ($def['defaults'] ?? [] as $k => $v) {
            $f[$k] ??= $v;
        }
        if (isset($def['defaults']['cutoff']) && ! $request->filled('corte')) {
            $f['cutoff'] = $def['defaults']['cutoff'];
        }
        if (isset($def['defaults']['nc']) && ! $request->has('nc')) {
            $f['nc'] = (bool) $def['defaults']['nc'];
        }
        $format = $request->input('formato') ?? ($request->boolean('export') ? 'xlsx' : null);

        if (isset($def['rows'])) {
            $rows = collect(($def['rows'])($f));
            $totals = collect($def['totals'])->mapWithKeys(fn ($k) => [$k => $rows->sum(fn ($r) => (float) ($r[$k] ?? 0))])->all();
            if ($format) {
                return $this->export($slug, $def, $f, $rows, $totals ?: null, $format);
            }

            return $this->render($slug, $def, $f, ['data' => $rows->values()], $totals);
        }

        $query = ($def['query'])($f);
        $map = $def['map'] ?? null;

        $totals = [];
        if ($def['totals']) {
            $sums = implode(', ', array_map(fn ($k) => "sum(`$k`) `$k`", $def['totals']));
            $totals = (array) DB::query()->fromSub((clone $query)->reorder(), 't')->selectRaw($sums)->first();
            if ($map) {
                $totals = (array) $map((object) [...$totals, 'entradas' => $totals['entradas'] ?? 0, 'salidas' => $totals['salidas'] ?? 0]);
            }
        }

        if ($format) {
            $rows = $query->limit(200000)->get();

            return $this->export($slug, $def, $f, $map ? $rows->map($map) : $rows, $totals ?: null, $format);
        }

        if (($def['paginate'] ?? true) === false) {
            $rows = $query->get();
            $page = ['data' => $map ? $rows->map($map) : $rows];
        } else {
            $page = $query->paginate(min(max($request->integer('per_page', 50), 10), 500))->withQueryString();
            if ($map) {
                $page->setCollection($page->getCollection()->map($map));
            }
        }

        return $this->render($slug, $def, $f, $page, $totals);
    }

    private function render(string $slug, array $def, array $f, $rows, array $totals): Response
    {
        $uses = fn (string $k) => in_array($k, $def['filters'], true);

        return Inertia::render('Reports/Generic', [
            'slug' => $slug,
            'report' => [
                'title' => $def['title'], 'note' => $def['note'] ?? null, 'filters' => $def['filters'], 'primary' => $def['primary'] ?? [],
                'columns' => $def['columns'], 'totals' => $def['totals'], 'links' => $def['links'] ?? [],
                'labels' => [...self::FILTER_LABELS, ...($def['labels'] ?? [])], 'defaults' => $def['defaults'] ?? [],
                'module_labels' => $def['module_labels'] ?? null, 'column_picker' => $def['column_picker'] ?? false, 'narrow' => $def['narrow'] ?? [], 'banner' => $def['banner'] ?? false, 'refresh' => $def['refresh'] ?? false, 'hidden' => $def['hidden'] ?? [], 'q_placeholder' => $def['q_placeholder'] ?? null,
            ],
            'rows' => $rows,
            'totals' => $totals ?: null,
            'filters' => [
                ...collect($f)->except('cid')->all(),
                'contact_label' => $f['contact'] ? Contact::find($f['contact'])?->only('identification', 'name') : null,
                'product_label' => $f['product'] ? Product::find($f['product'])?->only('code', 'name') : null,
            ],
            'options' => [
                'sellers' => $uses('seller') ? Seller::orderBy('name')->get(['id', 'name']) : [],
                'lines' => $uses('line') ? ProductLine::orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']) : [],
                'groups' => $uses('group') ? DB::table('account_groups')->where('company_id', $f['cid'])->orderBy('name')->get(['id', 'name']) : [],
                'cost_centers' => $uses('cost_center') ? DB::table('cost_centers')->where('company_id', $f['cid'])->orderBy('code')->get(['id', 'code', 'name']) : [],
                'payments' => $uses('payment') ? DB::table('payment_methods')->where('company_id', $f['cid'])->orderBy('name')->get(['id', 'name']) : [],
                'creators' => $uses('creator') ? DB::table('users')->where('company_id', $f['cid'])->orderBy('name')->get(['id', 'name']) : [],
                'warehouses' => $uses('warehouse') ? DB::table('warehouses')->where('company_id', $f['cid'])->orderBy('name')->get(['id', 'name']) : [],
                'doctypes' => $uses('doctype')
                    ? DB::table('ledger_lines')->where('company_id', $f['cid'])->distinct()->orderBy('document_type')->pluck('document_type') : [],
                'kinds' => $def['kinds'] ?? [],
                'statuses' => $def['statuses'] ?? [],
                'years' => range((int) now()->year, 2021),
            ],
        ]);
    }

    private function filters(Request $request): array
    {
        $date = fn (string $k, string $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($k)) ? $request->input($k) : $default;
        $id = fn (string $k) => $request->integer($k) ?: null;

        return [
            'cid' => CurrentCompany::id(),
            'from' => $date('desde', now()->startOfMonth()->toDateString()),
            'to' => $date('hasta', now()->endOfMonth()->toDateString()),
            'cutoff' => $date('corte', now()->toDateString()),
            'year' => min(max($request->integer('anio', (int) now()->year), 2000), 2100),
            'month' => min(max($request->integer('mes', (int) now()->month), 1), 12),
            'contact' => $id('tercero'),
            'product' => $id('producto'),
            'seller' => $id('vendedor'),
            'line' => $id('linea'),
            'group' => $id('grupo'),
            'cost_center' => $id('centro'),
            'payment' => $id('forma_pago'),
            'creator' => $id('creador'),
            'warehouse' => $id('bodega'),
            'module' => $request->input('modulo') === 'compras' ? 'compras' : 'ventas',
            'level' => min(max($request->integer('nivel', 3), 1), 5),
            'account' => preg_replace('/\D/', '', (string) $request->input('cuenta')) ?: null,
            'doctype' => $request->input('tipo') ?: null,
            'kind' => $request->input('clase') ?: null,
            'status' => $request->input('estado') ?: null,
            'dian' => in_array($request->input('dian'), ['Accepted', 'Rejected', 'Pending'], true) ? $request->input('dian') : null,
            'q' => trim((string) $request->input('q')) ?: null,
            'nc' => $request->input('nc', '1') !== '0',
            'pos' => $request->input('pos') === '1',
            'tax' => $request->input('impuesto') === '1',
            'preset' => $request->input('periodo') ?: null,
        ];
    }

    private function export(string $slug, array $def, array $f, $rows, ?array $totals, string $format)
    {
        DB::table('report_exports')->insert([
            'company_id' => CurrentCompany::id(), 'user_id' => auth()->id(), 'slug' => $slug, 'title' => $def['title'],
            'format' => $format, 'url' => mb_substr(request()->fullUrl(), 0, 2000), 'rows_count' => $rows->count(), 'created_at' => now(),
        ]);
        $cols = $def['columns'];
        $keys = array_keys($cols);
        $data = $rows->map(fn ($r) => array_map(fn ($k) => ((array) $r)[$k] ?? null, $keys));
        $foot = $totals ? array_map(fn ($k, $i) => in_array($k, $def['totals'], true) ? ($totals[$k] ?? null) : ($i === 0 ? 'Total' : null), $keys, array_keys($keys)) : null;
        $filters = $this->describe($def, $f);
        $file = $def['title'].' '.now()->format('Y-m-d');

        if ($format === 'pdf') {
            return TablePdf::download("$file.pdf", $def['title'], array_values($cols), $data, $foot, $filters);
        }

        return ArrayExport::download("$file.xlsx", array_column($cols, 0), $data, $def['title'], array_column($cols, 1), $def['title'], $filters, $foot);
    }

    /** Filtros aplicados en texto, para el encabezado del Excel y del PDF. */
    private function describe(array $def, array $f): array
    {
        $labels = [...self::FILTER_LABELS, ...($def['labels'] ?? [])];
        $d = fn ($v) => Carbon::parse($v)->format('d/m/Y');
        $out = [];
        foreach ($def['filters'] as $k) {
            $v = match ($k) {
                'range' => $d($f['from']).' a '.$d($f['to']),
                'cutoff' => $d($f['cutoff']),
                'year' => (string) $f['year'],
                'period' => ucfirst(Carbon::create($f['year'], $f['month'])->locale('es')->translatedFormat('F Y')),
                'contact' => $f['contact'] ? Contact::find($f['contact'])?->name : null,
                'product' => $f['product'] ? Product::find($f['product'])?->name : null,
                'seller' => $f['seller'] ? Seller::find($f['seller'])?->name : null,
                'nc' => $f['nc'] ? 'Sí' : 'No',
                'pos' => $f['pos'] ? 'Sí' : 'No',
                'tax' => $f['tax'] ? 'Sí' : 'No',
                'module' => $def['module_labels'][$f['module']] ?? ($f['module'] === 'compras' ? 'Cuentas por pagar' : 'Cuentas por cobrar'),
                'account' => $f['account'],
                'q' => $f['q'],
                default => null,
            };
            if ($v !== null && $v !== '') {
                $out[$labels[$k] ?? $k] = $v;
            }
        }

        return $out;
    }
}
