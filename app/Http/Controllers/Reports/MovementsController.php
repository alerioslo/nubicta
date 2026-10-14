<?php

namespace App\Http\Controllers\Reports;

use App\Exports\MovementsExport;
use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Models\Seller;
use App\Support\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Productos vendidos" del modulo de deposito, ahora sobre la base local:
 * mismos filtros (fechas, categoria, IVA) y columnas, mas vendedor, tipo de documento y resumenes.
 */
class MovementsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $view = $request->input('vista', 'detalle');
        $base = $this->query($filters);

        $totals = (clone $base)->selectRaw('count(*) n_lines, sum(i.quantity * s.sign) quantity, sum(i.total * s.sign) total, count(distinct d.id) documents')->first();

        $rows = match ($view) {
            'categoria' => (clone $base)
                ->selectRaw("coalesce(l.name, 'Desconocida') label, sum(i.quantity * s.sign) quantity, sum(i.total * s.sign) total, count(distinct i.product_id) products")
                ->groupBy('l.name')->orderByDesc('total')->get(),
            'producto' => (clone $base)
                ->selectRaw("i.code, max(i.description) label, coalesce(max(l.name), 'Desconocida') line, sum(i.quantity * s.sign) quantity, sum(i.total * s.sign) total")
                ->groupBy('i.code')->orderByDesc('quantity')
                ->paginate(50)->withQueryString(),
            'vendedor' => (clone $base)
                ->selectRaw("coalesce(se.name, 'Sin vendedor') label, sum(i.quantity * s.sign) quantity, sum(i.total * s.sign) total, count(distinct d.id) documents")
                ->groupBy('se.name')->orderByDesc('total')->get(),
            default => $this->detail($base)->paginate($request->integer('per_page', 50))->withQueryString(),
        };

        return Inertia::render('Reports/Movements', [
            'rows' => $rows,
            'view' => $view,
            'totals' => $totals,
            'filters' => [...$filters, 'from' => $filters['from']->format('Y-m-d'), 'to' => $filters['to']->format('Y-m-d')],
            'lines' => ProductLine::orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']),
            'sellers' => Seller::orderBy('name')->get(['id', 'name']),
            'ivaOptions' => DB::table('sales_document_items')->whereNotNull('tax_percentage')->distinct()->orderBy('tax_percentage')->pluck('tax_percentage')->map(fn ($v) => (float) $v),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $name = 'movimientos_'.$filters['from']->format('Ymd').'_'.$filters['to']->format('Ymd').'.xlsx';

        return Excel::download(new MovementsExport($this->detail($this->query($filters))), $name);
    }

    private function filters(Request $request): array
    {
        $from = $request->date('desde') ?? Carbon::today()->startOfMonth();
        $to = $request->date('hasta') ?? Carbon::today();

        return [
            'from' => $from,
            'to' => $to,
            'categoria' => $request->input('categoria'),
            'iva' => $request->input('iva'),
            'vendedor' => $request->input('vendedor'),
            'tipo' => $request->input('tipo', 'FV'),
            'q' => $request->input('q'),
        ];
    }

    private function query(array $f): Builder
    {
        $types = $f['tipo'] === 'todos' ? ['FV', 'NC'] : [$f['tipo'] === 'NC' ? 'NC' : 'FV'];

        return DB::table('sales_document_items as i')
            ->join('sales_documents as d', 'd.id', '=', 'i.sales_document_id')
            ->joinSub(DB::query()->selectRaw("'FV' t, 1 sign union all select 'NC', -1"), 's', 's.t', '=', 'd.type')
            ->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_lines as l', 'l.id', '=', 'p.product_line_id')
            ->leftJoin('sellers as se', 'se.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)'))
            ->where('d.company_id', CurrentCompany::id())
            ->whereIn('d.type', $types)
            ->where('d.status', '!=', 'annulled')
            ->whereBetween('d.date', [$f['from']->format('Y-m-d'), $f['to']->format('Y-m-d')])
            ->when($f['categoria'] === 'ninguna', fn ($q) => $q->whereNull('l.id'))
            ->when($f['categoria'] && $f['categoria'] !== 'ninguna', fn ($q) => $q->where('l.id', $f['categoria']))
            ->when($f['iva'] !== null && $f['iva'] !== '', fn ($q) => $f['iva'] === 'ninguno'
                ? $q->whereNull('i.tax_percentage')
                : $q->where('i.tax_percentage', $f['iva']))
            ->when($f['vendedor'], fn ($q, $v) => $q->where('se.id', $v))
            ->when($f['q'], fn ($q, $t) => $q->where(fn ($w) => $w->where('i.code', 'like', "$t%")->orWhere('i.description', 'like', "%$t%")->orWhere('d.name', 'like', "%$t%")->orWhere('c.name', 'like', "%$t%")->orWhere('c.identification', 'like', "$t%")));
    }

    private function detail(Builder $base): Builder
    {
        return (clone $base)
            ->select([
                'i.id', 'i.code', 'd.name as document', 'd.type', 'd.date', 'i.description as product',
                'c.name as customer', 'c.identification', 'i.price', 'i.quantity', 'i.total', 'se.name as seller',
                'l.name as line', 'i.tax_percentage', 's.sign',
            ])
            ->orderByDesc('d.date')->orderByDesc('d.number')->orderBy('i.position');
    }
}
