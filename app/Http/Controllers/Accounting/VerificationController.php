<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Contabilidad > Mas procesos > Verifica y bloquea comprobantes: un comprobante verificado no se puede editar ni anular. */
class VerificationController extends Controller
{
    private const TYPES = [
        'ventas' => ['FV' => 'Factura de venta', 'NC' => 'Nota crédito', 'ND' => 'Nota débito'],
        'compras' => ['FC' => 'Compras', 'DS' => 'Documento soporte', 'ND' => 'Nota débito compras', 'NA' => 'Nota ajuste documento soporte'],
        'recibos' => ['RC' => 'Recibo de caja', 'RP' => 'Recibo de pago'],
        'comprobantes' => ['CC' => 'Comprobante contable'],
    ];

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $rows = $this->query($f)->paginate(50)->withQueryString()->through(fn ($d) => [
            ['select' => "{$d->kind}:{$d->id}"],
            self::TYPES[$d->kind][$d->type] ?? $d->type,
            ['text' => $d->name, 'href' => "/documentos/{$d->kind}/{$d->id}"],
            $d->supplier_ref ?: '',
            $d->identification ?? '',
            $d->branch_office ?: '',
            ['text' => $d->contact_name ?? '', 'truncate' => true],
            ['text' => number_format((float) $d->total, 2, '.', ','), 'align' => 'right'],
            $d->currency && $d->currency !== 'COP' ? $d->currency : '',
            substr((string) $d->date, 8, 2).'/'.substr((string) $d->date, 5, 2).'/'.substr((string) $d->date, 0, 4),
            (string) $d->attachments,
            ['text' => $d->verified_at ? 'Verificado' : 'Sin verificar', 'badge' => $d->verified_at ? 'ok' : 'off'],
            ['actions' => [$d->verified_at
                ? ['text' => 'Quitar verificación', 'post' => '/contabilidad/verifica-documentos', 'data' => ['ids' => ["{$d->kind}:{$d->id}"], 'accion' => 'quitar']]
                : ['text' => 'Verificar', 'post' => '/contabilidad/verifica-documentos', 'data' => ['ids' => ["{$d->kind}:{$d->id}"], 'accion' => 'verificar']]]],
        ]);
        $types = collect(self::TYPES)->flatten()->unique()->values()->all();

        return Inertia::render('GenericList', [
            'view' => [
                'title' => 'Contabilidad', 'tab' => 'Más procesos', 'heading' => 'Verifica y bloquea comprobantes', 'url' => '/contabilidad/verifica-documentos',
                'columns' => ['#select', 'Tipo Comprobante', 'Comprobante', 'Factura proveedor', 'Identificación', 'Sucursal', 'Tercero', 'Valor', 'Moneda', 'Fecha elaboración', 'Documento soporte', 'Estado', 'Acción'],
                'search' => 'Tercero: nombre o identificación', 'search_label' => 'Tercero', 'dates' => true,
                'selects' => [
                    ['name' => 'estado', 'label' => 'Estado', 'options' => ['Verificado', 'Sin verificar']],
                    ['name' => 'tipo', 'label' => 'Tipo comprobante', 'options' => $types],
                    ['name' => 'creado', 'label' => 'Creado por', 'options' => User::query()->orderBy('name')->pluck('name')->all()],
                ],
                'info' => 'Al verificar un comprobante queda bloqueado: no se puede editar ni anular hasta que se le quite la verificación.',
                'buttons' => [
                    ['label' => 'Verificar seleccionados', 'icon' => 'circle-check', 'selection' => 'post', 'url' => '/contabilidad/verifica-documentos?accion=verificar', 'confirm' => 'Se verificarán y bloquearán :n comprobantes. ¿Continuar?'],
                    ['label' => 'Quitar verificación', 'style' => 'outline', 'selection' => 'post', 'url' => '/contabilidad/verifica-documentos?accion=quitar', 'confirm' => 'Se quitará la verificación de :n comprobantes. ¿Continuar?'],
                    ['label' => 'Descargar', 'icon' => 'download', 'style' => 'outline', 'download' => '/contabilidad/verifica-documentos/excel'],
                ],
                'empty' => ['No hay comprobantes en el rango', 'Ajusta los criterios de búsqueda'],
            ],
            'rows' => $rows,
            'tabs' => DashboardController::tabs('contabilidad', 'Más procesos'),
            'filters' => $f,
        ]);
    }

    public function excel(Request $request)
    {
        $rows = $this->query($this->filters($request))->limit(20000)->get()->map(fn ($d) => [
            self::TYPES[$d->kind][$d->type] ?? $d->type, $d->name, $d->supplier_ref ?: '', $d->identification ?? '', $d->branch_office ?: '', $d->contact_name ?? '',
            (float) $d->total, $d->currency ?: 'COP', substr((string) $d->date, 0, 10), (int) $d->attachments, $d->verified_at ? 'Verificado' : 'Sin verificar',
        ])->all();

        return \App\Support\Download::array('verifica_comprobantes.xlsx', ['Tipo comprobante', 'Comprobante', 'Factura proveedor', 'Identificación', 'Sucursal', 'Tercero', 'Valor', 'Moneda', 'Fecha elaboración', 'Documento soporte', 'Estado'], $rows, 'Comprobantes');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'regex:/^(ventas|compras|recibos|comprobantes):\d+$/']]);
        $verify = $request->input('accion') !== 'quitar';
        $n = 0;
        foreach ($request->input('ids') as $key) {
            [$kind, $id] = explode(':', $key);
            if ($verify) {
                $n += DB::table('document_verifications')->insertOrIgnore([
                    'company_id' => CurrentCompany::id(), 'kind' => $kind, 'document_id' => (int) $id, 'verified_by' => Auth::id(), 'verified_at' => now(),
                ]);
            } else {
                $n += DB::table('document_verifications')->where('company_id', CurrentCompany::id())->where('kind', $kind)->where('document_id', (int) $id)->delete();
            }
        }

        return back()->with('success', $verify ? "Se verificaron y bloquearon {$n} comprobante(s)." : "Se quitó la verificación de {$n} comprobante(s).");
    }

    public static function isVerified(string $kind, int $id): bool
    {
        return DB::table('document_verifications')->where('kind', $kind)->where('document_id', $id)->exists();
    }

    private function filters(Request $request): array
    {
        return $request->only('q', 'estado', 'tipo', 'creado') + [
            'desde' => $request->input('desde', now()->subDays(30)->toDateString()),
            'hasta' => $request->input('hasta', now()->toDateString()),
        ];
    }

    private function query(array $f): Builder
    {
        $company = CurrentCompany::id();
        $part = fn (string $kind, string $table, string $ref, string $currency, string $types = '') => DB::table("$table as d")
            ->where('d.company_id', $company)->whereBetween('d.date', [$f['desde'], $f['hasta']])
            ->when($types, fn ($q) => $q->whereIn('d.type', array_keys(self::TYPES[$kind])))
            ->selectRaw("'$kind' as kind, d.id, ".($kind === 'comprobantes' ? "'CC'" : 'd.type')." as type, d.name, $ref as supplier_ref, "
                .($kind === 'comprobantes' ? 'null' : 'd.contact_id').' as contact_id, '.($kind === 'comprobantes' ? 'd.debit' : 'd.total')." as total, $currency as currency, d.date, d.created_by");
        $union = $part('ventas', 'sales_documents', 'null', 'd.currency', 'types')
            ->unionAll($part('compras', 'purchase_documents', "nullif(concat_ws('-', nullif(d.supplier_prefix, ''), d.supplier_number), '')", 'd.currency', 'types'))
            ->unionAll($part('recibos', 'receipts', 'null', 'null'))
            ->unionAll($part('comprobantes', 'journal_entries', 'null', 'null'));

        $typeFilter = $f['tipo'] ?? null;
        $attachClass = ['ventas' => 'App\\\\Models\\\\SalesDocument', 'compras' => 'App\\\\Models\\\\PurchaseDocument', 'recibos' => 'App\\\\Models\\\\Receipt', 'comprobantes' => 'App\\\\Models\\\\JournalEntry'];
        $attachCase = 'case u.kind '.collect($attachClass)->map(fn ($c, $k) => "when '$k' then '$c'")->implode(' ').' end';

        return DB::query()->fromSub($union, 'u')
            ->leftJoin('contacts as c', 'c.id', '=', 'u.contact_id')
            ->leftJoin('document_verifications as v', fn ($j) => $j->on('v.kind', '=', 'u.kind')->on('v.document_id', '=', 'u.id'))
            ->when(trim((string) ($f['q'] ?? '')), fn ($q, $t) => $q->where(fn ($w) => $w->where('c.name', 'like', "%$t%")->orWhere('c.identification', 'like', "$t%")))
            ->when(($f['estado'] ?? '') === 'Verificado', fn ($q) => $q->whereNotNull('v.id'))
            ->when(($f['estado'] ?? '') === 'Sin verificar', fn ($q) => $q->whereNull('v.id'))
            ->when($typeFilter, fn ($q) => $q->where(function ($w) use ($typeFilter) {
                foreach (self::TYPES as $kind => $types) {
                    foreach ($types as $code => $label) {
                        if ($label === $typeFilter) {
                            $w->orWhere(fn ($x) => $x->where('u.kind', $kind)->where('u.type', $code));
                        }
                    }
                }
            }))
            ->when($f['creado'] ?? null, fn ($q, $name) => $q->whereIn('u.created_by', User::query()->where('name', $name)->pluck('id')))
            ->selectRaw("u.*, c.identification, c.name as contact_name, c.branch_office, v.verified_at,
                (select count(*) from attachments a where a.attachable_type = $attachCase and a.attachable_id = u.id) as attachments")
            ->orderBy('u.date')->orderBy('u.name');
    }
}
