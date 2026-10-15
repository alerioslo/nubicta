<?php

namespace App\Http\Controllers;

use App\Models\InventoryCosting;
use App\Models\SalesDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestanas de Siigo que son listados sencillos (muchos vacios en la empresa): misma cabecera, filtros y columnas.
 * Las que tienen datos propios usan un "source".
 */
class ModuleViewController extends Controller
{
    private const VIEWS = [
        'ventas/cotizaciones' => [
            'title' => 'Ventas', 'module' => 'ventas', 'tab' => 'Cotizaciones', 'search_label' => 'Usuario', 'search' => 'Usuario',
            'dates' => true,
            'buttons' => [['label' => 'Crear cotización', 'href' => '/ventas/cotizaciones/crear']],
            'columns' => ['Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Total', 'Moneda'],
            'empty' => ['Aún no tienes cotizaciones en este periodo', 'Crea una cotización para enviarla a tu cliente'],
            'source' => 'quotes',
        ],
        'ventas/remisiones' => [
            'title' => 'Ventas', 'module' => 'ventas', 'tab' => 'Remisiones', 'heading' => 'Remisiones', 'search_label' => 'Cliente', 'search' => 'Buscar por nombre o identificación',
            'dates' => true,
            'buttons' => [['label' => 'Crear remisión', 'href' => '/ventas/remisiones/crear']],
            'columns' => ['Tipo transacción', 'Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Estado envío de correo', 'Total', 'Moneda'],
            'source' => 'remissions',
        ],
        'ventas/envio-masivo' => [
            'title' => 'Ventas', 'module' => 'ventas', 'tab' => 'Envío masivo', 'heading' => 'Impresión y envío masivo de comprobantes',
            'search_label' => 'Clientes', 'search' => 'Buscar por nombre o identificación', 'dates' => true,
            'buttons' => [
                ['label' => 'Imprimir', 'icon' => 'print', 'style' => 'outline', 'selection' => 'open', 'url' => '/ventas/envio-masivo/imprimir'],
                ['label' => 'Enviar', 'icon' => 'envelope', 'selection' => 'post', 'url' => '/ventas/envio-masivo/enviar', 'confirm' => 'Se enviarán :n comprobantes al correo de cada cliente. ¿Continuar?'],
            ],
            'columns' => ['#select', 'Tipo transacción', 'Comprobante', 'Fecha de elaboración', 'Identificación', 'Sucursal', 'Clientes', 'Correo electrónico', 'Total', 'Envío por email', 'Envío a la DIAN'],
            'source' => 'massiveSend',
        ],
        'compras/soporte-legalizar' => [
            'title' => 'Compras y gastos', 'module' => 'compras', 'tab' => 'Soporte por legalizar', 'heading' => 'Soporte de compras o gastos por legalizar',
            'search_label' => 'Documento soporte', 'search' => 'Buscar', 'dates' => true,
            'selects' => [['name' => 'estado', 'label' => 'Estado', 'options' => ['Sin causar', 'Causado']]],
            'buttons' => [['label' => 'Cargar soporte', 'icon' => 'cloud-arrow-up', 'upload' => '/compras/soporte-legalizar', 'accept' => '.pdf,.jpg,.jpeg,.png,.webp,.heic,.xml,.zip']],
            'columns' => ['Documento soporte', 'Fecha creación', 'Nombre Comprobante', 'Número de comprobante', 'Estado', 'Acción'],
            'empty' => ['No se encontraron resultados para tu búsqueda', 'Carga la foto o el PDF del soporte para causarlo como factura de compra'],
            'source' => 'supports',
        ],
        'productos/documentos' => [
            'title' => 'Productos y servicios', 'module' => 'productos', 'tab' => 'Documentos de productos / servicios', 'heading' => 'Inventarios',
            'search_label' => 'Comprobante', 'search' => 'Buscar por comprobante u observación', 'dates' => true, 'default_days' => 30,
            'buttons' => [
                ['label' => 'Importar', 'style' => 'outline', 'menu' => [
                    ['label' => 'Importar comprobantes de ajuste', 'href' => '/inventario/ajustes/crear?importar=1'],
                    ['label' => 'Importar saldos iniciales de inventario', 'href' => '/contabilidad/saldos-iniciales/inventario'],
                    ['label' => 'Importar conteo físico', 'href' => '/inventario/conteos/crear?importar=1'],
                ]],
                ['label' => 'Nuevo documento', 'menu' => [
                    ['label' => 'Ajuste de inventario', 'href' => '/inventario/ajustes/crear'], ['label' => 'Ensamble de producto', 'href' => '/inventario/ensambles/crear'],
                    ['label' => 'Conteo físico / Ajustes', 'href' => '/inventario/conteos/crear'], ['label' => 'Remisión', 'href' => '/ventas/remisiones/crear'],
                    ['label' => 'Traslado entre bodegas', 'href' => '/inventario/traslados/crear'],
                ]],
                ['label' => 'Descargar', 'icon' => 'download', 'style' => 'outline', 'download' => '/productos/documentos/excel'],
            ],
            'columns' => ['Tipo transacción', 'Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Total'],
            'empty' => ['No se encontraron resultados para tu búsqueda.', 'Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo.'],
            'source' => 'inventoryDocs',
        ],
        'productos/costeo' => [
            'title' => 'Productos y servicios', 'module' => 'productos', 'tab' => 'Costeo mensual',
            'info' => 'Para garantizar que el costo de ventas sea preciso, registra todos los documentos que impacten el inventario, como facturas de venta y compra, ensambles, conteos físicos y ajustes de inventario.',
            'year' => true, 'buttons' => [['label' => 'Crear documento', 'menu' => [
                ['label' => 'Ajuste de inventario', 'href' => '/inventario/ajustes/crear'], ['label' => 'Ensamble de producto', 'href' => '/inventario/ensambles/crear'],
                ['label' => 'Conteo físico / Ajustes', 'href' => '/inventario/conteos/crear'], ['label' => 'Factura de venta', 'href' => '/ventas/facturas/crear'], ['label' => 'Factura de compra', 'href' => '/compras/facturas/crear'],
            ]]],
            'columns' => ['Mes', 'Ultima ejecución', 'Estado', 'Acciones'],
            'source' => 'costing',
        ],
    ];

    /** Pestanas "Mas procesos": tarjetas con los procesos de Siigo (url = pantalla del ERP cuando existe). */
    private const PROCESSES = [
        'nomina/mas-procesos' => ['title' => 'Nómina', 'module' => 'nomina', 'tab' => 'Más procesos', 'cards' => [
            ['Planilla pago seguridad social', [['Liquidación planilla integrada de aportes', '/nomina/pila']]],
            ['Históricos', [['Libro de vacaciones', '/nomina/vacaciones']]],
            ['Otros procesos', [['Liquidación de contrato', '/nomina/crear?tipo=liquidacion'], ['Liquidación de prima', '/nomina/crear?tipo=prima'],
                ['Cesantías anuales', '/nomina/crear?tipo=cesantias'], ['Generar certificado de ingresos y retenciones / Formulario 220', '/nomina/certificado-ingresos']]],
        ]],
        'contabilidad/mas-procesos' => ['title' => 'Contabilidad', 'module' => 'contabilidad', 'tab' => 'Más procesos', 'cards' => [
            ['Contables', [['Diferencia en cambio', '/contabilidad/diferencia-cambio'], ['Cierre de año', '/contabilidad/cierre-anio'], ['Cierre cuentas de impuestos y otras cuentas por tercero', '/contabilidad/cierre-impuestos'],
                ['Verifica y bloquea documentos', '/contabilidad/verifica-documentos'], ['Copias de comprobantes favoritos', '/contabilidad/comprobantes-favoritos'], ['Borrado masivo de comprobantes', '/contabilidad/borrado-masivo']]],
            ['Medios Magnéticos', [['Asistente de medios magnéticos', '/contabilidad/medios-magneticos'], ['Informe auxiliar medios magnéticos distritales y/o municipales', '/contabilidad/medios-municipales']]],
            ['Activos fijos', [['Creación de activo fijo', '/configuracion/activos-fijos/crear'], ['Importar activo fijo', '/contabilidad/activos-fijos/importar']]],
            ['Certificados', [['Generar certificado de retención a título de renta, IVA e ICA', '/contabilidad/certificados-retencion'], ['Generar certificado de ingresos y retenciones / Formulario 220', '/nomina/certificado-ingresos']]],
            ['Saldos Iniciales', [['Saldos iniciales de cuentas por cobrar', '/contabilidad/saldos-iniciales/cuentas-por-cobrar'], ['Saldos iniciales de cuentas por pagar', '/contabilidad/saldos-iniciales/cuentas-por-pagar'], ['Saldos iniciales de inventario', '/contabilidad/saldos-iniciales/inventario']]],
            ['Saldos iniciales otras cuentas', [['Saldos iniciales de otras cuentas contables', '/contabilidad/saldos-iniciales/otras-cuentas']]],
        ]],
    ];

    public function processes(Request $request): Response
    {
        $view = self::PROCESSES[trim($request->route()->uri(), '/')];

        return Inertia::render('ProcessMenu', [
            'title' => $view['title'],
            'active' => $view['tab'],
            'tabs' => DashboardController::tabs($view['module']),
            'cards' => collect($view['cards'])->map(fn ($c) => ['title' => $c[0], 'items' => collect($c[1])->map(fn ($i) => ['label' => $i[0], 'url' => $i[1]])->all()])->all(),
        ]);
    }

    public static function processRoutes(): array
    {
        return array_keys(self::PROCESSES);
    }

    public function show(Request $request): Response
    {
        $key = trim($request->route()->uri(), '/');
        $view = self::VIEWS[$key] ?? abort(404);
        if (isset($view['default_days']) && ! $request->hasAny(['desde', 'hasta', 'q', 'page', 'todos'])) {
            $request->merge(['desde' => now()->subDays($view['default_days'])->toDateString()]);
            $view['chips'] = [['text' => "Fecha elaboración: Últimos días: {$view['default_days']}", 'clear' => "/$key?todos=1"]];
        }
        $rows = isset($view['source']) ? $this->{$view['source']}($request) : [];

        return Inertia::render('GenericList', [
            'view' => collect($view)->except(['source', 'default_days'])->all() + ['url' => '/'.$key],
            'rows' => $rows,
            'tabs' => DashboardController::tabs($view['module'], $view['tab']),
            'filters' => $request->only(['q', 'desde', 'hasta', 'sub', 'anio', ...collect($view['selects'] ?? [])->pluck('name')->all()]),
        ]);
    }

    public static function routes(): array
    {
        return array_keys(self::VIEWS);
    }

    /** Descarga en Excel de un listado generico con los filtros aplicados. */
    public function excel(Request $request)
    {
        $key = preg_replace('#/excel$#', '', trim($request->route()->uri(), '/'));
        $view = self::VIEWS[$key] ?? abort(404);
        $request->merge(['per_page' => 5000]);
        $rows = $this->{$view['source']}($request);
        $rows = collect(is_array($rows) ? $rows : $rows->items())->map(fn ($r) => collect($r)->map(fn ($c) => is_array($c) ? ($c['text'] ?? '') : $c)->all());

        return \App\Support\Download::array(str_replace('/', '_', $key).'.xlsx', $view['columns'], $rows->all(), $view['heading'] ?? $view['title']);
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }

    private function supports(Request $request)
    {
        return \App\Models\ExpenseSupport::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where('name', 'like', "%$t%"))
            ->when($request->input('estado'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(function (\App\Models\ExpenseSupport $s) {
                $doc = $s->purchase_document_id ? \App\Models\PurchaseDocument::query()->find($s->purchase_document_id) : null;
                $type = $doc ? \App\Models\DocumentType::query()->find($doc->document_type_id) : null;

                return [
                    ['text' => $s->name, 'download' => "/compras/soporte-legalizar/{$s->id}"],
                    $s->created_at->format('d/m/Y'),
                    $doc ? ($type?->name ?? $doc->type) : '',
                    $doc ? ['text' => $doc->name, 'href' => \App\Services\Documents\DocumentLocator::url($doc)] : '',
                    ['text' => $s->status, 'badge' => $doc ? 'ok' : 'warn'],
                    ['actions' => $doc ? [] : [
                        ['text' => 'Causar', 'href' => "/compras/facturas/crear?soporte={$s->id}"],
                        ['text' => 'Eliminar', 'post' => "/compras/soporte-legalizar/{$s->id}/eliminar", 'danger' => true, 'confirm' => '¿Eliminar este soporte?'],
                    ]],
                ];
            });
    }

    private function massiveSend(Request $request)
    {
        $from = $request->input('desde', now()->toDateString());
        $to = $request->input('hasta', now()->toDateString());

        return SalesDocument::query()->with('contact:id,name,identification,branch_office,email')
            ->whereIn('type', ['FV', 'NC', 'ND'])
            ->whereBetween('date', [$from, $to])
            ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->orderByDesc('number')
            ->paginate(50)->withQueryString()
            ->through(fn (SalesDocument $d) => [
                ['select' => $d->id], SalesDocument::TYPES[$d->type] ?? $d->type, ['text' => $d->name, 'href' => "/documentos/ventas/{$d->id}"], $d->date->format('d/m/Y'),
                $d->contact?->identification, $d->contact?->branch_office ?: '-', $d->contact?->name, $d->contact?->email ?? '-',
                ['text' => $this->money((float) $d->total), 'align' => 'right'],
                $d->mailBadge(),
                ['text' => ['Accepted' => 'Aprobada', 'Rejected' => 'Rechazada'][$d->dian_status] ?? ($d->dian_status ? 'Pendiente' : 'Sin enviar'),
                    'badge' => ['Accepted' => 'ok', 'Rejected' => 'danger'][$d->dian_status] ?? 'off'],
            ]);
    }

    /** Cotizaciones (CT) o remisiones (RM) con filtro de fechas y cliente. */
    private function salesList(Request $request, string $type)
    {
        $from = $request->input('desde', now()->subDays($type === 'RM' ? 15 : 30)->toDateString());
        $to = $request->input('hasta', now()->toDateString());

        return SalesDocument::query()->with('contact:id,name,identification,branch_office')
            ->where('type', $type)->whereBetween('date', [$from, $to])
            ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate(20)->withQueryString()
            ->through(fn (SalesDocument $d) => array_values(array_filter([
                $type === 'RM' ? 'Remisión' : null,
                ['text' => $d->name.($d->status === 'annulled' ? ' (Anulada)' : ''), 'href' => "/documentos/ventas/{$d->id}"],
                $d->date->format('d/m/Y'), $d->contact?->identification, $d->contact?->branch_office ?: '-', ['text' => $d->contact?->name, 'truncate' => true],
                $type === 'RM' ? $d->mailBadge() : null,
                ['text' => $this->money((float) $d->total), 'align' => 'right'], $d->currency ?? 'COP',
            ], fn ($v) => $v !== null)));
    }

    private function quotes(Request $request)
    {
        return $this->salesList($request, 'CT');
    }

    private function remissions(Request $request)
    {
        return $this->salesList($request, 'RM');
    }

    /** Traslados, ajustes, conteos fisicos y ensambles elaborados en el ERP. */
    private function inventoryDocs(Request $request)
    {
        $kinds = ['inventory_traslado' => 'Traslado entre bodegas', 'inventory_ajuste' => 'Ajuste de inventario', 'inventory_conteo' => 'Conteo físico', 'inventory_ensamble' => 'Nota de ensamble'];

        return \App\Models\JournalEntry::query()->whereIn('source_type', array_keys($kinds))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('observations', 'like', "%$t%")))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 5000))->withQueryString()
            ->through(function ($e) use ($kinds) {
                $contact = \App\Models\Contact::query()->whereKey(\Illuminate\Support\Facades\DB::table('journal_lines')->where('journal_entry_id', $e->id)->whereNotNull('contact_id')->value('contact_id'))->first();

                return [
                    $kinds[$e->source_type], ['text' => $e->name.($e->status === 'annulled' ? ' (Anulado)' : ''), 'href' => "/documentos/comprobantes/{$e->id}"],
                    $e->date->format('d/m/Y'), $contact?->identification ?? '', $contact ? (string) ($contact->branch_office ?? 0) : '', $contact?->name ?? '',
                    ['text' => $this->money((float) $e->debit), 'align' => 'right'],
                ];
            });
    }

    /** Acciones del mes como en Siigo: Descargar detalle con desplegable Ver comprobante / Volver a costear. */
    private function costingActions(?InventoryCosting $c, bool $ok, bool $canRun, string $url, string $month, int $year, int $m): array
    {
        $confirm = "Se recalculará el costo promedio de los movimientos de {$month} {$year} y se contabilizará la diferencia en el costo de ventas. ¿Continuar?";
        if (! $ok) {
            return ['actions' => $canRun ? [['text' => 'Calcular costeo', 'post' => $url, 'confirm' => $confirm]] : []];
        }
        $end = \Illuminate\Support\Carbon::create($year, $m, 1)->endOfMonth()->toDateString();
        $voucher = \App\Models\JournalEntry::query()->where('source_type', 'inventory_costing')->where('source_id', $c->id)->where('status', '!=', 'annulled')->value('id')
            ?? \App\Models\JournalEntry::query()->where('name', 'like', 'CC-3-%')->whereDate('date', $end)->orderBy('number')->value('id');

        return ['split' => ['text' => 'Descargar detalle', 'download' => "$url/excel"], 'menu' => array_values(array_filter([
            $voucher ? ['text' => 'Ver comprobante', 'href' => "/documentos/comprobantes/$voucher"] : null,
            $canRun ? ['text' => 'Volver a costear', 'post' => $url, 'confirm' => $confirm] : null,
        ]))];
    }

    private function costing(Request $request): array
    {
        $year = (int) $request->input('anio', now()->year);
        $done = InventoryCosting::where('year', $year)->get()->keyBy('month');
        $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $last = InventoryCosting::query()->where('status', 'Costeado')->orderByDesc('year')->orderByDesc('month')->first();

        return collect($months)->map(function ($m, $i) use ($done, $year, $last) {
            $c = $done->get($i + 1);
            $ok = $c && $c->status === 'Costeado';
            $isLast = $last && (int) $last->year === $year && (int) $last->month === $i + 1;
            $next = ! $last || \Illuminate\Support\Carbon::create((int) $last->year, (int) $last->month, 1)->addMonth()->isSameMonth(\Illuminate\Support\Carbon::create($year, $i + 1, 1));
            $url = "/productos/costeo/{$year}/".($i + 1);
            $started = now()->startOfMonth()->gte(\Illuminate\Support\Carbon::create($year, $i + 1, 1));

            return [
                $m,
                $c?->executed_at?->translatedFormat('j M Y') ?? '',
                ['text' => $ok ? 'Costeado' : 'Sin costear', 'badge' => $ok ? 'ok' : 'danger'],
                $this->costingActions($c, $ok, $started && ($ok ? $isLast : $next), $url, $m, $year, $i + 1),
            ];
        })->all();
    }
}
