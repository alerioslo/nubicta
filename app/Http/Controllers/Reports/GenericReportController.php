<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductLine;
use App\Models\Seller;
use App\Reports\ReportRegistry;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Pantalla unica para los informes de ReportRegistry: filtros, tabla, totales y descarga a Excel. */
class GenericReportController extends Controller
{
    public function show(Request $request, string $slug): Response|StreamedResponse
    {
        $def = ReportRegistry::all()[$slug] ?? abort(404);
        $f = $this->filters($request);

        if (isset($def['rows'])) {
            $rows = collect(($def['rows'])($f));
            if ($request->boolean('export')) {
                return $this->export($slug, $def, $rows);
            }

            return $this->render($slug, $def, $f, ['data' => $rows->values()], []);
        }

        $query = ($def['query'])($f);
        $map = $def['map'] ?? null;

        if ($request->boolean('export')) {
            $rows = $query->limit(200000)->get();

            return $this->export($slug, $def, $map ? $rows->map($map) : $rows);
        }

        $totals = [];
        if ($def['totals']) {
            $sums = implode(', ', array_map(fn ($k) => "sum(`$k`) `$k`", $def['totals']));
            $totals = (array) DB::query()->fromSub((clone $query)->reorder(), 't')->selectRaw($sums)->first();
            if ($map) {
                $totals = $map((object) [...$totals, 'entradas' => $totals['entradas'] ?? 0, 'salidas' => $totals['salidas'] ?? 0]);
            }
        }

        if (($def['paginate'] ?? true) === false) {
            $rows = $query->get();
            $page = ['data' => $map ? $rows->map($map) : $rows];
        } else {
            $page = $query->paginate($request->integer('per_page', 50))->withQueryString();
            if ($map) {
                $page->setCollection($page->getCollection()->map($map));
            }
        }

        return $this->render($slug, $def, $f, $page, $totals);
    }

    private function render(string $slug, array $def, array $f, $rows, array $totals): Response
    {
        return Inertia::render('Reports/Generic', [
            'slug' => $slug,
            'report' => ['title' => $def['title'], 'note' => $def['note'] ?? null, 'filters' => $def['filters'], 'columns' => $def['columns'], 'totals' => $def['totals']],
            'rows' => $rows,
            'totals' => $totals ?: null,
            'filters' => [
                ...collect($f)->except('cid')->all(),
                'contact_label' => $f['contact'] ? Contact::find($f['contact'])?->only('identification', 'name') : null,
                'product_label' => $f['product'] ? Product::find($f['product'])?->only('code', 'name') : null,
            ],
            'options' => [
                'sellers' => in_array('seller', $def['filters'], true) ? Seller::orderBy('name')->get(['id', 'name']) : [],
                'lines' => in_array('line', $def['filters'], true) ? ProductLine::orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']) : [],
                'doctypes' => in_array('doctype', $def['filters'], true)
                    ? DB::table('ledger_lines')->where('company_id', $f['cid'])->distinct()->orderBy('document_type')->pluck('document_type') : [],
                'years' => range((int) now()->year, 2021),
            ],
        ]);
    }

    private function filters(Request $request): array
    {
        $date = fn (string $k, string $default) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($k)) ? $request->input($k) : $default;

        return [
            'cid' => CurrentCompany::id(),
            'from' => $date('desde', now()->startOfMonth()->toDateString()),
            'to' => $date('hasta', now()->toDateString()),
            'cutoff' => $date('corte', now()->toDateString()),
            'year' => min(max($request->integer('anio', (int) now()->year), 2000), 2100),
            'contact' => $request->integer('tercero') ?: null,
            'product' => $request->integer('producto') ?: null,
            'seller' => $request->integer('vendedor') ?: null,
            'line' => $request->integer('linea') ?: null,
            'module' => $request->input('modulo') === 'compras' ? 'compras' : 'ventas',
            'level' => min(max($request->integer('nivel', 3), 1), 5),
            'account' => preg_replace('/\D/', '', (string) $request->input('cuenta')) ?: null,
            'doctype' => $request->input('tipo') ?: null,
            'dian' => in_array($request->input('dian'), ['Accepted', 'Rejected', 'Pending'], true) ? $request->input('dian') : null,
            'q' => trim((string) $request->input('q')) ?: null,
        ];
    }

    private function export(string $slug, array $def, $rows): StreamedResponse
    {
        DB::table('report_exports')->insert([
            'company_id' => CurrentCompany::id(), 'user_id' => auth()->id(), 'slug' => $slug, 'title' => $def['title'],
            'rows_count' => $rows->count(), 'created_at' => now(),
        ]);
        $cols = $def['columns'];

        return response()->streamDownload(function () use ($rows, $cols) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $c[0], $cols), ';');
            foreach ($rows as $r) {
                $r = (array) $r;
                fputcsv($out, array_map(fn ($k, $c) => in_array($c[1], ['money', 'qty', 'pct'], true) && $r[$k] !== null
                    ? number_format((float) $r[$k], 2, ',', '') : ($r[$k] ?? ''), array_keys($cols), $cols), ';');
            }
            fclose($out);
        }, $slug.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
