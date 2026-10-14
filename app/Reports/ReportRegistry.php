<?php

namespace App\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Informes del catalogo de Siigo resueltos con una consulta: filtros, columnas, totales y exportacion.
 * Columnas: [clave => [titulo, formato]] con formato text|money|qty|int|pct|date.
 */
final class ReportRegistry
{
    public const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const SIGN = "(CASE WHEN d.type = 'NC' THEN -1 ELSE 1 END)";

    /** @return array<string, array> */
    public static function all(): array
    {
        return [
            // ---------------- Ventas ----------------
            'ventas-cliente-producto' => [
                'title' => 'Ventas por cliente por producto', 'filters' => ['range', 'contact', 'product'],
                'columns' => ['ident' => ['Identificación', 'text'], 'cliente' => ['Cliente', 'text'], 'codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'],
                    'cantidad' => ['Cantidad', 'qty'], 'subtotal' => ['Valor bruto', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::salesItems($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.identification ident, c.name cliente, i.code codigo, max(i.description) producto, '.self::itemSums())
                    ->groupBy('c.identification', 'c.name', 'i.code')->orderBy('c.name')->orderByDesc('total'),
            ],
            'ventas-vendedor-producto' => [
                'title' => 'Ventas por vendedor por producto', 'filters' => ['range', 'seller', 'product'],
                'columns' => ['vendedor' => ['Vendedor', 'text'], 'codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'],
                    'cantidad' => ['Cantidad', 'qty'], 'subtotal' => ['Valor bruto', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::salesItems($f)->leftJoin('sellers as s', 's.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)'))
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, i.code codigo, max(i.description) producto, ".self::itemSums())
                    ->groupBy('s.name', 'i.code')->orderBy('vendedor')->orderByDesc('total'),
            ],
            'ventas-centro-costo' => [
                'title' => 'Ventas por centro de costo', 'filters' => ['range'],
                'columns' => ['centro' => ['Centro de costo', 'text'], 'documentos' => ['Documentos', 'int'], 'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::sales($f)->leftJoin('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
                    ->selectRaw("coalesce(concat(cc.code, ' - ', cc.name), 'Sin centro de costo') centro, count(*) documentos, ".self::docSums())
                    ->groupBy('cc.code', 'cc.name')->orderByDesc('total'),
            ],
            'comparativo-ventas-mes' => [
                'title' => 'Comparativo de ventas por mes', 'filters' => ['year'],
                'columns' => ['mes' => ['Mes', 'text'], 'anterior' => ['Año anterior', 'money'], 'actual' => ['Año seleccionado', 'money'], 'variacion' => ['Variación', 'money'], 'porcentaje' => ['% Variación', 'pct']],
                'totals' => ['anterior', 'actual', 'variacion'],
                'query' => fn ($f) => DB::query()->fromSub(
                    self::sales([...$f, 'from' => ($f['year'] - 1).'-01-01', 'to' => $f['year'].'-12-31'])
                        ->selectRaw('month(d.date) m, sum(case when year(d.date) = ? then d.subtotal * '.self::SIGN.' else 0 end) anterior, sum(case when year(d.date) = ? then d.subtotal * '.self::SIGN.' else 0 end) actual', [$f['year'] - 1, $f['year']])
                        ->groupByRaw('month(d.date)'), 't')
                    ->selectRaw("elt(m, 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre') mes, anterior, actual, actual - anterior variacion, case when anterior <> 0 then (actual - anterior) / anterior * 100 end porcentaje")
                    ->orderBy('m'),
            ],
            'comparativo-ventas-producto' => [
                'title' => 'Comparativo de ventas por producto', 'filters' => ['year', 'product'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], ...self::monthColumns()],
                'totals' => [...array_keys(self::monthColumns())],
                'query' => fn ($f) => self::salesItems([...$f, 'from' => $f['year'].'-01-01', 'to' => $f['year'].'-12-31'])
                    ->selectRaw('i.code codigo, max(i.description) producto, '.self::monthSums('i.subtotal * '.self::SIGN))
                    ->groupBy('i.code')->orderByDesc('total'),
            ],
            'comparativo-ventas-vendedor' => [
                'title' => 'Comparativo de ventas por vendedor', 'filters' => ['year', 'seller'],
                'columns' => ['vendedor' => ['Vendedor', 'text'], ...self::monthColumns()],
                'totals' => [...array_keys(self::monthColumns())],
                'query' => fn ($f) => self::sales([...$f, 'from' => $f['year'].'-01-01', 'to' => $f['year'].'-12-31'])
                    ->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, ".self::monthSums('d.subtotal * '.self::SIGN))
                    ->groupBy('s.name')->orderByDesc('total'),
            ],
            'facturacion-obsequios' => [
                'title' => 'Informe facturación de obsequios', 'filters' => ['range', 'contact'],
                'note' => 'Ítems facturados con valor cero o descuento del 100 %.',
                'columns' => ['documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'codigo' => ['Código', 'text'],
                    'producto' => ['Producto', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'costo' => ['Costo', 'money']],
                'totals' => ['cantidad', 'costo'],
                'query' => fn ($f) => self::salesItems($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->whereRaw('(i.price = 0 or i.discount >= i.quantity * i.price)')
                    ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                    ->selectRaw('d.name documento, d.date fecha, c.name cliente, i.code codigo, i.description producto, i.quantity cantidad, i.quantity * coalesce(p.average_cost, 0) costo')
                    ->orderBy('d.date'),
            ],
            'cotizaciones-vendedor' => [
                'title' => 'Cotizaciones por vendedor', 'filters' => ['range', 'seller'],
                'columns' => ['vendedor' => ['Vendedor', 'text'], 'documentos' => ['Cotizaciones', 'int'], 'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::sales($f, ['CT'])->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, count(*) documentos, ".self::docSums())->groupBy('s.name')->orderByDesc('total'),
            ],
            'facturacion-electronica' => [
                'title' => 'Informes facturación electrónica', 'filters' => ['range', 'contact', 'dian'],
                'columns' => ['documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'total' => ['Total', 'money'],
                    'estado' => ['Estado DIAN', 'text'], 'correo' => ['Estado correo', 'text'], 'cufe' => ['CUFE', 'text']],
                'totals' => ['total'],
                'query' => fn ($f) => self::sales($f, ['FV', 'NC', 'ND'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->when($f['dian'], fn ($q, $v) => $q->where('d.dian_status', $v))
                    ->selectRaw("d.name documento, d.date fecha, c.name cliente, d.total * ".self::SIGN." total, case d.dian_status when 'Accepted' then 'Aceptado' when 'Rejected' then 'Rechazado' when 'Pending' then 'Pendiente' else coalesce(d.dian_status, 'Sin enviar') end estado, coalesce(d.mail_status, '-') correo, d.cufe")
                    ->orderByDesc('d.date')->orderByDesc('d.number'),
            ],
            'recibos-caja-facturas' => [
                'title' => 'Recibos de caja detallado por facturas', 'filters' => ['range', 'contact'],
                'columns' => ['recibo' => ['Recibo', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'factura' => ['Factura', 'text'],
                    'vence' => ['Vencimiento', 'date'], 'forma' => ['Forma de pago', 'text'], 'valor' => ['Valor', 'money']],
                'totals' => ['valor'],
                'query' => fn ($f) => self::receipts($f, 'RC')
                    ->selectRaw("r.name recibo, r.date fecha, c.name cliente, coalesce(sd.name, concat_ws('-', ri.due_prefix, ri.due_number), ri.description) factura, ri.due_date vence, r.payment_name forma, ri.value valor")
                    ->leftJoin('sales_documents as sd', 'sd.id', '=', 'ri.sales_document_id')->orderBy('r.date')->orderBy('r.number'),
            ],
            'seguimiento-correos' => [
                'title' => 'Seguimiento envío masivo de correos electrónicos', 'filters' => ['range', 'contact'],
                'columns' => ['documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'email' => ['Correo', 'text'], 'estado' => ['Estado del envío', 'text']],
                'totals' => [],
                'query' => fn ($f) => self::sales($f, ['FV', 'NC'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("d.name documento, d.date fecha, c.name cliente, c.email, coalesce(d.mail_status, 'No enviado') estado")->orderByDesc('d.date'),
            ],

            // ---------------- Cartera ----------------
            'cartera-detallada' => [
                'title' => 'Cuentas por cobrar/pagar detallada por documento', 'filters' => ['module', 'contact', 'cutoff'],
                'columns' => ['ident' => ['Identificación', 'text'], 'tercero' => ['Tercero', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'vence' => ['Vencimiento', 'date'],
                    'dias' => ['Días vencidos', 'int'], 'total' => ['Valor documento', 'money'], 'sin_vencer' => ['Sin vencer', 'money'], 'd30' => ['1 a 30', 'money'], 'd60' => ['31 a 60', 'money'],
                    'd90' => ['61 a 90', 'money'], 'mas90' => ['Más de 90', 'money'], 'saldo' => ['Saldo', 'money']],
                'totals' => ['total', 'sin_vencer', 'd30', 'd60', 'd90', 'mas90', 'saldo'],
                'query' => fn ($f) => self::aging($f),
            ],
            'cartera-movimiento' => [
                'title' => 'Movimiento de cuentas por cobrar/pagar por documento', 'filters' => ['module', 'range', 'contact'],
                'columns' => ['tercero' => ['Tercero', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'total' => ['Valor documento', 'money'],
                    'abonos' => ['Abonos / pagos', 'money'], 'saldo' => ['Saldo actual', 'money']],
                'totals' => ['total', 'abonos', 'saldo'],
                'query' => function ($f) {
                    $purchases = $f['module'] === 'compras';
                    $table = $purchases ? 'purchase_documents' : 'sales_documents';
                    $fk = $purchases ? 'purchase_document_id' : 'sales_document_id';

                    return DB::table("$table as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
                        ->whereIn('d.type', $purchases ? ['FC', 'DS'] : ['FV'])->whereBetween('d.date', [$f['from'], $f['to']])
                        ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
                        ->selectRaw("c.name tercero, d.name documento, d.date fecha, d.total, (select coalesce(sum(ri.value), 0) from receipt_items ri where ri.$fk = d.id) abonos, d.balance saldo")
                        ->orderBy('d.date')->orderBy('d.number');
                },
            ],
            'cxc-corte' => [
                'title' => 'Cuentas por cobrar con corte a una fecha', 'filters' => ['cutoff', 'contact'],
                'columns' => ['ident' => ['Identificación', 'text'], 'cliente' => ['Cliente', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'vence' => ['Vencimiento', 'date'],
                    'total' => ['Valor documento', 'money'], 'actual' => ['Saldo actual', 'money'], 'posteriores' => ['Abonos después del corte', 'money'], 'saldo' => ['Saldo al corte', 'money']],
                'totals' => ['total', 'actual', 'posteriores', 'saldo'],
                'note' => 'Saldo al corte = saldo actual de la factura + abonos recibidos después de la fecha de corte.',
                'query' => fn ($f) => DB::query()->fromSub(
                    DB::table('sales_documents as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])->where('d.type', 'FV')
                        ->where('d.status', '<>', 'annulled')->where('d.date', '<=', $f['cutoff'])->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
                        ->selectRaw('c.identification ident, c.name cliente, d.name documento, d.date fecha, d.due_date vence, d.total, d.balance actual,
                            (select coalesce(sum(ri.value), 0) from receipt_items ri join receipts r on r.id = ri.receipt_id where ri.sales_document_id = d.id and r.date > ? and r.status <> ?) posteriores', [$f['cutoff'], 'annulled']), 't')
                    ->selectRaw('t.*, actual + posteriores saldo')->whereRaw('actual + posteriores > 0.009')->orderBy('cliente')->orderBy('fecha'),
            ],
            'busqueda-contactos' => [
                'title' => 'Búsqueda avanzada contacto de cliente, proveedor u otro', 'filters' => ['q'],
                'columns' => ['tercero' => ['Tercero', 'text'], 'ident' => ['Identificación', 'text'], 'contacto' => ['Contacto', 'text'], 'cargo' => ['Cargo', 'text'], 'email' => ['Correo', 'text'], 'telefono' => ['Teléfono', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::table('contact_people as p')->join('contacts as c', 'c.id', '=', 'p.contact_id')->where('c.company_id', $f['cid'])
                    ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('c.name', 'like', "%$v%")->orWhere('p.first_name', 'like', "%$v%")->orWhere('p.last_name', 'like', "%$v%")
                        ->orWhere('p.email', 'like', "%$v%")->orWhere('c.identification', 'like', "$v%")))
                    ->selectRaw("c.name tercero, c.identification ident, trim(concat(p.first_name, ' ', coalesce(p.last_name, ''))) contacto, p.position cargo, p.email, trim(concat(coalesce(p.phone_indicative, ''), ' ', coalesce(p.phone, ''))) telefono")
                    ->orderBy('c.name'),
            ],

            // ---------------- Compras ----------------
            'compras-proveedor' => [
                'title' => 'Compras por proveedor', 'filters' => ['range', 'contact'],
                'columns' => ['ident' => ['Identificación', 'text'], 'proveedor' => ['Proveedor', 'text'], 'documentos' => ['Documentos', 'int'], 'subtotal' => ['Subtotal', 'money'],
                    'impuestos' => ['Impuestos', 'money'], 'retenciones' => ['Retenciones', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchases($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.identification ident, c.name proveedor, count(*) documentos, sum(d.subtotal * '.self::PSIGN.') subtotal, sum(d.tax_total * '.self::PSIGN.') impuestos, sum(d.withholding_total * '.self::PSIGN.') retenciones, sum(d.total * '.self::PSIGN.') total')
                    ->groupBy('c.identification', 'c.name')->orderByDesc('total'),
            ],
            'compras-producto' => [
                'title' => 'Compras por producto', 'filters' => ['range', 'product'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto / cuenta', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::purchaseItems($f)->selectRaw("coalesce(i.code, '-') codigo, max(i.description) producto, ".self::purchaseItemSums())
                    ->groupBy('i.code')->orderByDesc('total'),
            ],
            'compras-producto-proveedor' => [
                'title' => 'Compras por producto por proveedor', 'filters' => ['range', 'contact', 'product'],
                'columns' => ['proveedor' => ['Proveedor', 'text'], 'codigo' => ['Código', 'text'], 'producto' => ['Producto / cuenta', 'text'], 'cantidad' => ['Cantidad', 'qty'],
                    'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::purchaseItems($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("c.name proveedor, coalesce(i.code, '-') codigo, max(i.description) producto, ".self::purchaseItemSums())
                    ->groupBy('c.name', 'i.code')->orderBy('c.name')->orderByDesc('total'),
            ],
            'cruce-orden-compra' => [
                'title' => 'Cruce orden de compra vs factura de compra', 'filters' => ['range', 'contact'],
                'columns' => ['orden' => ['Orden de compra', 'text'], 'fecha' => ['Fecha', 'date'], 'proveedor' => ['Proveedor', 'text'], 'valor_orden' => ['Valor orden', 'money'],
                    'facturado' => ['Facturado', 'money'], 'pendiente' => ['Pendiente', 'money']],
                'totals' => ['valor_orden', 'facturado', 'pendiente'],
                'query' => fn ($f) => self::purchases($f, ['OC'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('d.name orden, d.date fecha, c.name proveedor, d.total valor_orden, d.total - d.balance facturado, d.balance pendiente')->orderBy('d.date'),
            ],
            'movimiento-notas-debito' => [
                'title' => 'Movimiento notas débito', 'filters' => ['range', 'contact'],
                'columns' => ['modulo' => ['Módulo', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'tercero' => ['Tercero', 'text'], 'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::sales($f, ['ND'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("'Ventas' modulo, d.name documento, d.date fecha, c.name tercero, d.subtotal, d.tax_total impuestos, d.total")
                    ->unionAll(self::purchases($f, ['ND', 'NA'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                        ->selectRaw("'Compras' modulo, d.name documento, d.date fecha, c.name tercero, d.subtotal, d.tax_total impuestos, d.total")),
            ],
            'libro-oficial-compras' => [
                'title' => 'Libro oficial de compras', 'filters' => ['range', 'contact'],
                'columns' => ['fecha' => ['Fecha', 'date'], 'documento' => ['Comprobante', 'text'], 'factura' => ['Factura proveedor', 'text'], 'ident' => ['NIT', 'text'], 'proveedor' => ['Proveedor', 'text'],
                    'subtotal' => ['Base', 'money'], 'impuestos' => ['IVA', 'money'], 'retenciones' => ['Retenciones', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchases($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("d.date fecha, d.name documento, concat_ws('-', d.supplier_prefix, d.supplier_number) factura, c.identification ident, c.name proveedor, d.subtotal * ".self::PSIGN.' subtotal, d.tax_total * '.self::PSIGN.' impuestos, d.withholding_total * '.self::PSIGN.' retenciones, d.total * '.self::PSIGN.' total')
                    ->orderBy('d.date')->orderBy('d.number'),
            ],

            // ---------------- Ventas oficiales e impuestos ----------------
            'libro-oficial-ventas' => [
                'title' => 'Libro oficial de ventas', 'filters' => ['range', 'contact'],
                'columns' => ['fecha' => ['Fecha', 'date'], 'documento' => ['Comprobante', 'text'], 'ident' => ['NIT', 'text'], 'cliente' => ['Cliente', 'text'],
                    'subtotal' => ['Base', 'money'], 'descuento' => ['Descuento', 'money'], 'impuestos' => ['IVA', 'money'], 'retenciones' => ['Retenciones', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['subtotal', 'descuento', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::sales($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('d.date fecha, d.name documento, c.identification ident, c.name cliente, d.subtotal * '.self::SIGN.' subtotal, d.discount * '.self::SIGN.' descuento, d.tax_total * '.self::SIGN.' impuestos, d.withholding_total * '.self::SIGN.' retenciones, d.total * '.self::SIGN.' total')
                    ->orderBy('d.date')->orderBy('d.number'),
            ],
            'impuestos-detallados' => [
                'title' => 'Impuestos detallados', 'filters' => ['range'],
                'columns' => ['modulo' => ['Módulo', 'text'], 'impuesto' => ['Impuesto', 'text'], 'tipo' => ['Tipo', 'text'], 'porcentaje' => ['%', 'pct'], 'base' => ['Base', 'money'], 'valor' => ['Valor', 'money']],
                'totals' => ['base', 'valor'],
                'query' => fn ($f) => self::salesItems($f)->join('sales_document_item_taxes as t', 't.sales_document_item_id', '=', 'i.id')
                    ->selectRaw("'Ventas' modulo, t.name impuesto, t.type tipo, t.percentage porcentaje, sum(i.subtotal * ".self::SIGN.') base, sum(t.value * '.self::SIGN.') valor')
                    ->groupBy('t.name', 't.type', 't.percentage')
                    ->unionAll(self::purchaseItems($f)->join('purchase_document_item_taxes as t', 't.purchase_document_item_id', '=', 'i.id')
                        ->selectRaw("'Compras' modulo, t.name impuesto, t.type tipo, t.percentage porcentaje, sum(i.subtotal * ".self::PSIGN.') base, sum(t.value * '.self::PSIGN.') valor')
                        ->groupBy('t.name', 't.type', 't.percentage')),
            ],

            // ---------------- Inventario ----------------
            'costeo-mensual' => [
                'title' => 'Informe costeo mensual', 'filters' => ['year'],
                'note' => 'Ventas netas de facturas y notas crédito; costo de ventas tomado de la cuenta 6135 del libro contable.',
                'columns' => ['mes' => ['Mes', 'text'], 'ventas' => ['Ventas', 'money'], 'costo' => ['Costo de ventas', 'money'], 'utilidad' => ['Utilidad bruta', 'money'], 'margen' => ['Margen', 'pct'], 'costeo' => ['Proceso de costeo', 'text']],
                'totals' => ['ventas', 'costo', 'utilidad'],
                'query' => fn ($f) => DB::query()->fromSub(
                    DB::query()->fromSub(
                        self::sales([...$f, 'from' => $f['year'].'-01-01', 'to' => $f['year'].'-12-31'])
                            ->selectRaw('month(d.date) m, sum(d.subtotal * '.self::SIGN.') ventas')->groupByRaw('month(d.date)'), 's')
                        ->selectRaw('s.m, s.ventas, (select coalesce(sum(g.debit - g.credit), 0) from ledger_lines g where g.company_id = ? and g.account_code like ? and g.date between ? and ? and month(g.date) = s.m) costo',
                            [$f['cid'], '6135%', $f['year'].'-01-01', $f['year'].'-12-31']), 't')
                    ->leftJoin('inventory_costings as ic', fn ($j) => $j->on('ic.month', '=', 't.m')->where('ic.year', $f['year'])->where('ic.company_id', $f['cid']))
                    ->selectRaw("elt(t.m, 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre') mes, ventas, costo, ventas - costo utilidad,
                        case when ventas <> 0 then (ventas - costo) / ventas * 100 end margen, case when ic.executed_at is not null then concat('Ejecutado ', date_format(ic.executed_at, '%d/%m/%Y')) else 'Pendiente' end costeo")
                    ->orderBy('t.m'),
            ],
            'rentabilidad-producto' => [
                'title' => 'Rentabilidad por producto', 'filters' => ['range', 'product', 'line'],
                'note' => 'Costo calculado con el costo promedio ponderado de las compras de cada producto.',
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], 'linea' => ['Línea', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'ventas' => ['Ventas', 'money'],
                    'costo' => ['Costo', 'money'], 'utilidad' => ['Utilidad', 'money'], 'margen' => ['Margen', 'pct']],
                'totals' => ['cantidad', 'ventas', 'costo', 'utilidad'],
                'query' => fn ($f) => self::salesItems($f)->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('product_lines as l', 'l.id', '=', 'p.product_line_id')
                    ->when($f['line'], fn ($q, $v) => $q->where('p.product_line_id', $v))
                    ->selectRaw('i.code codigo, max(i.description) producto, max(l.name) linea, sum(i.quantity * '.self::SIGN.') cantidad, sum(i.subtotal * '.self::SIGN.') ventas, sum(i.quantity * coalesce(p.average_cost, 0) * '.self::SIGN.') costo,
                        sum(i.subtotal * '.self::SIGN.') - sum(i.quantity * coalesce(p.average_cost, 0) * '.self::SIGN.') utilidad,
                        case when sum(i.subtotal * '.self::SIGN.') <> 0 then (sum(i.subtotal * '.self::SIGN.') - sum(i.quantity * coalesce(p.average_cost, 0) * '.self::SIGN.')) / sum(i.subtotal * '.self::SIGN.') * 100 end margen')
                    ->groupBy('i.code')->orderByDesc('utilidad'),
            ],
            'valoracion-inventarios' => [
                'title' => 'Valoración de inventarios', 'filters' => ['product', 'line'],
                'note' => 'Existencias importadas de Siigo valoradas al costo promedio ponderado de compras. Compáralo con el saldo contable de la cuenta 14 en el balance de prueba.',
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], 'linea' => ['Línea', 'text'], 'unidad' => ['Unidad', 'text'], 'existencia' => ['Existencia', 'qty'],
                    'costo' => ['Costo promedio', 'money'], 'valor' => ['Valor total', 'money']],
                'totals' => ['existencia', 'valor'],
                'query' => fn ($f) => self::products($f)->selectRaw('p.code codigo, p.name producto, l.name linea, p.unit_label unidad, p.stock existencia, p.average_cost costo, p.stock * p.average_cost valor')->orderBy('p.code'),
            ],
            'saldos-productos' => [
                'title' => 'Saldos de productos', 'filters' => ['product', 'line'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], 'bodega' => ['Bodega', 'text'], 'existencia' => ['Existencia', 'qty'], 'minimo' => ['Stock mínimo', 'qty'], 'estado' => ['Estado', 'text']],
                'totals' => ['existencia'],
                'query' => fn ($f) => self::products($f)->leftJoin('warehouse_stock as ws', 'ws.product_id', '=', 'p.id')->leftJoin('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                    ->selectRaw("p.code codigo, p.name producto, coalesce(w.name, 'Principal') bodega, coalesce(ws.quantity, p.stock) existencia, p.min_stock minimo,
                        case when coalesce(ws.quantity, p.stock) <= 0 then 'Agotado' when p.min_stock > 0 and coalesce(ws.quantity, p.stock) < p.min_stock then 'Bajo mínimo' else 'Disponible' end estado")
                    ->orderBy('p.code'),
            ],
            'saldos-inventario' => [
                'title' => 'Saldos de inventario', 'filters' => ['line'],
                'columns' => ['linea' => ['Línea', 'text'], 'productos' => ['Productos', 'int'], 'existencia' => ['Existencia', 'qty'], 'valor' => ['Valor inventario', 'money']],
                'totals' => ['productos', 'existencia', 'valor'],
                'query' => fn ($f) => self::products([...$f, 'product' => null])
                    ->selectRaw("coalesce(concat(l.code, ' - ', l.name), 'Sin línea') linea, count(*) productos, sum(p.stock) existencia, sum(p.stock * p.average_cost) valor")
                    ->groupBy('l.code', 'l.name')->orderBy('l.code'),
            ],
            'movimiento-productos' => [
                'title' => 'Movimiento de productos', 'filters' => ['range', 'product', 'line'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], 'entradas' => ['Entradas (compras)', 'qty'], 'salidas' => ['Salidas (ventas)', 'qty'],
                    'neto' => ['Movimiento neto', 'qty'], 'existencia' => ['Existencia actual', 'qty']],
                'totals' => ['entradas', 'salidas', 'neto'],
                'query' => fn ($f) => self::products($f)
                    ->selectRaw("p.code codigo, p.name producto,
                        (select coalesce(sum(pi.quantity), 0) from purchase_document_items pi join purchase_documents pd on pd.id = pi.purchase_document_id where pi.product_id = p.id and pd.date between ? and ? and pd.status <> 'annulled') entradas,
                        (select coalesce(sum(si.quantity * case when sd.type = 'NC' then -1 else 1 end), 0) from sales_document_items si join sales_documents sd on sd.id = si.sales_document_id where si.product_id = p.id and sd.date between ? and ? and sd.status <> 'annulled') salidas,
                        p.stock existencia", [$f['from'], $f['to'], $f['from'], $f['to']])
                    ->havingRaw('entradas <> 0 or salidas <> 0')
                    ->selectRaw('0 neto')->orderBy('p.code'),
                'map' => fn ($r) => [...(array) $r, 'neto' => (float) $r->entradas - (float) $r->salidas],
            ],
            'ensambles' => [
                'title' => 'Ensamble producto proceso / terminado', 'filters' => ['range', 'product'],
                'columns' => ['documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'costo' => ['Costo total', 'money']],
                'totals' => ['cantidad', 'costo'],
                'query' => fn ($f) => self::movements($f, 'NE'),
            ],
            'remisiones-cruzadas' => [
                'title' => 'Informe de remisiones cruzadas', 'filters' => ['range', 'contact'],
                'columns' => ['remision' => ['Remisión', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'factura' => ['Factura', 'text'], 'total' => ['Valor', 'money']],
                'totals' => ['total'],
                'query' => fn ($f) => self::sales($f, ['RM'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("d.name remision, d.date fecha, c.name cliente, coalesce(d.related_name, 'Sin cruzar') factura, d.total")->orderBy('d.date'),
            ],

            // ---------------- Contabilidad ----------------
            'acumulados' => [
                'title' => 'Acumulados', 'filters' => ['year', 'level', 'account'],
                'columns' => ['cuenta' => ['Cuenta', 'text'], 'nombre' => ['Nombre', 'text'], ...self::monthColumns()],
                'totals' => [],
                'note' => 'Movimiento neto (débitos menos créditos) de cada mes por cuenta.',
                'query' => fn ($f) => DB::query()->fromSub(
                    DB::table('ledger_lines as g')->where('g.company_id', $f['cid'])->whereBetween('g.date', [$f['year'].'-01-01', $f['year'].'-12-31'])
                        ->when($f['account'], fn ($q, $v) => $q->where('g.account_code', 'like', "$v%"))
                        ->selectRaw('left(g.account_code, '.self::levelLength($f['level']).') code, '.self::monthSums('g.debit - g.credit', 'g.date'))
                        ->groupByRaw('left(g.account_code, '.self::levelLength($f['level']).')'), 't')
                    ->leftJoin('accounts as a', fn ($j) => $j->on('a.code', '=', 't.code')->where('a.company_id', $f['cid']))
                    ->selectRaw('t.code cuenta, a.name nombre, t.*')->orderBy('t.code'),
            ],
            'consecutivo-comprobantes' => [
                'title' => 'Consecutivo de comprobantes', 'filters' => ['range'],
                'columns' => ['tipo' => ['Tipo de comprobante', 'text'], 'desde' => ['Desde', 'int'], 'hasta' => ['Hasta', 'int'], 'cantidad' => ['Comprobantes', 'int'], 'faltantes' => ['Números sin usar', 'int']],
                'totals' => ['cantidad', 'faltantes'],
                'query' => fn ($f) => DB::query()->fromSub(
                    DB::table('ledger_lines')->where('company_id', $f['cid'])->whereBetween('date', [$f['from'], $f['to']])
                        ->selectRaw("substring_index(document, '-', 2) tipo, cast(substring_index(document, '-', -1) as unsigned) num")->distinct(), 't')
                    ->selectRaw('tipo, min(num) desde, max(num) hasta, count(*) cantidad, max(num) - min(num) + 1 - count(*) faltantes')->groupBy('tipo')->orderBy('tipo'),
            ],
            'comprobantes-no-contabilizados' => [
                'title' => 'Comprobantes no contabilizados', 'filters' => ['range'],
                'columns' => ['modulo' => ['Módulo', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'tercero' => ['Tercero', 'text'], 'total' => ['Total', 'money']],
                'totals' => ['total'],
                'query' => fn ($f) => self::sales($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->whereNotExists(fn ($q) => $q->from('ledger_lines as g')->where('g.company_id', $f['cid'])->whereColumn('g.document', 'd.name'))
                    ->selectRaw("'Ventas' modulo, d.name documento, d.date fecha, c.name tercero, d.total")
                    ->unionAll(self::purchases($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                        ->whereNotExists(fn ($q) => $q->from('ledger_lines as g')->where('g.company_id', $f['cid'])->whereColumn('g.document', 'd.name'))
                        ->selectRaw("'Compras' modulo, d.name documento, d.date fecha, c.name tercero, d.total")),
            ],
            'comprobantes-detallados' => [
                'title' => 'Comprobantes detallados', 'filters' => ['range', 'doctype', 'account'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'fecha' => ['Fecha', 'date'], 'cuenta' => ['Cuenta', 'text'], 'nombre_cuenta' => ['Nombre cuenta', 'text'],
                    'ident' => ['Identificación', 'text'], 'tercero' => ['Tercero', 'text'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money']],
                'totals' => ['debito', 'credito'],
                'query' => fn ($f) => DB::table('ledger_lines as g')->leftJoin('accounts as a', 'a.id', '=', 'g.account_id')->where('g.company_id', $f['cid'])->whereBetween('g.date', [$f['from'], $f['to']])
                    ->when($f['doctype'], fn ($q, $v) => $q->where('g.document_type', $v))->when($f['account'], fn ($q, $v) => $q->where('g.account_code', 'like', "$v%"))
                    ->selectRaw('g.document documento, g.date fecha, g.account_code cuenta, a.name nombre_cuenta, g.identification ident, g.contact_name tercero, g.debit debito, g.credit credito')
                    ->orderBy('g.date')->orderBy('g.document')->orderBy('g.id'),
            ],
            'flujo-efectivo' => [
                'title' => 'Estado de flujos de efectivo - Método indirecto', 'filters' => ['range'], 'paginate' => false,
                'columns' => ['concepto' => ['Concepto', 'text'], 'valor' => ['Valor', 'money']],
                'totals' => [],
                'rows' => fn ($f) => self::cashFlow($f),
            ],
            'cambios-patrimonio' => [
                'title' => 'Estado de cambios en el patrimonio', 'filters' => ['range'], 'paginate' => false,
                'columns' => ['cuenta' => ['Cuenta', 'text'], 'nombre' => ['Nombre', 'text'], 'inicial' => ['Saldo inicial', 'money'], 'aumentos' => ['Aumentos', 'money'], 'disminuciones' => ['Disminuciones', 'money'], 'final' => ['Saldo final', 'money']],
                'totals' => ['inicial', 'aumentos', 'disminuciones', 'final'],
                'query' => fn ($f) => DB::table('ledger_lines as g')->join('accounts as a', fn ($j) => $j->on('a.code', '=', DB::raw('left(g.account_code, 4)'))->where('a.company_id', $f['cid']))
                    ->where('g.company_id', $f['cid'])->where('g.account_code', 'like', '3%')->where('g.date', '<=', $f['to'])
                    ->selectRaw('a.code cuenta, a.name nombre, sum(case when g.date < ? then g.credit - g.debit else 0 end) inicial, sum(case when g.date >= ? then g.credit else 0 end) aumentos,
                        sum(case when g.date >= ? then g.debit else 0 end) disminuciones, sum(g.credit - g.debit) final', [$f['from'], $f['from'], $f['from']])
                    ->groupBy('a.code', 'a.name')->orderBy('a.code'),
            ],
            'auxiliar-centro-costo' => [
                'title' => 'Movimiento Auxiliar Centro de costo por cuenta contable', 'filters' => ['range', 'account'],
                'columns' => ['centro' => ['Centro de costo', 'text'], 'cuenta' => ['Cuenta', 'text'], 'documento' => ['Comprobante', 'text'], 'fecha' => ['Fecha', 'date'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money']],
                'totals' => ['debito', 'credito'],
                'query' => fn ($f) => self::costCenterLines($f)->selectRaw("concat(cc.code, ' - ', cc.name) centro, concat(a.code, ' - ', a.name) cuenta, je.name documento, jl.date fecha, jl.debit debito, jl.credit credito")
                    ->orderBy('cc.code')->orderBy('a.code')->orderBy('jl.date'),
            ],
            'balance-centro-costo' => [
                'title' => 'Balance de prueba por centro de costo', 'filters' => ['range', 'account'],
                'columns' => ['centro' => ['Centro de costo', 'text'], 'cuenta' => ['Cuenta', 'text'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money'], 'saldo' => ['Saldo', 'money']],
                'totals' => ['debito', 'credito', 'saldo'],
                'query' => fn ($f) => self::costCenterLines($f)->selectRaw("concat(cc.code, ' - ', cc.name) centro, concat(a.code, ' - ', a.name) cuenta, sum(jl.debit) debito, sum(jl.credit) credito, sum(jl.debit - jl.credit) saldo")
                    ->groupBy('cc.code', 'cc.name', 'a.code', 'a.name')->orderBy('cc.code')->orderBy('a.code'),
            ],

            // ---------------- Nomina ----------------
            'acumulados-nomina-generales' => [
                'title' => 'Acumulados generales de nómina', 'filters' => ['year'],
                'columns' => ['liquidacion' => ['Liquidación', 'text'], 'tipo' => ['Tipo', 'text'], 'desde' => ['Desde', 'date'], 'hasta' => ['Hasta', 'date'], 'estado' => ['Estado', 'text'], 'total' => ['Total', 'money']],
                'totals' => ['total'],
                'query' => fn ($f) => DB::table('payroll_runs')->where('company_id', $f['cid'])->whereYear('period_start', $f['year'])
                    ->selectRaw('name liquidacion, settlement_type tipo, period_start desde, period_end hasta, status estado, total')->orderBy('period_start'),
            ],
            'acumulados-nomina-detallados' => [
                'title' => 'Acumulados detallados de nómina', 'filters' => ['year', 'contact'],
                'columns' => ['ident' => ['Identificación', 'text'], 'empleado' => ['Empleado', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'estado' => ['Estado DIAN', 'text'], 'neto' => ['Neto pagado', 'money']],
                'totals' => ['neto'],
                'query' => fn ($f) => DB::table('payroll_documents as pd')->leftJoin('contacts as c', 'c.id', '=', 'pd.contact_id')->where('pd.company_id', $f['cid'])
                    ->whereYear('pd.generated_at', $f['year'])->when($f['contact'], fn ($q, $v) => $q->where('pd.contact_id', $v))
                    ->selectRaw("c.identification ident, c.name empleado, concat_ws('-', pd.type, pd.consecutive) documento, pd.generated_at fecha, pd.status estado, pd.net neto")->orderBy('pd.generated_at'),
            ],
            'acumulados-iniciales-nomina' => [
                'title' => 'Acumulados iniciales de nómina', 'filters' => [],
                'columns' => ['ident' => ['Identificación', 'text'], 'empleado' => ['Empleado', 'text'], 'contrato' => ['Contrato', 'text'], 'inicio' => ['Fecha de ingreso', 'date'], 'cargo' => ['Cargo', 'text'], 'salario' => ['Salario', 'money'], 'activo' => ['Estado', 'text']],
                'totals' => ['salario'],
                'query' => fn ($f) => DB::table('employees as e')->join('contacts as c', 'c.id', '=', 'e.contact_id')->where('e.company_id', $f['cid'])
                    ->selectRaw("c.identification ident, c.name empleado, e.contract_number contrato, e.start_date inicio, e.position cargo, e.salary salario, if(e.active, 'Activo', 'Retirado') activo")->orderBy('c.name'),
            ],

            // ---------------- General ----------------
            'auditoria-borrado' => [
                'title' => 'Auditoría de borrado de comprobantes', 'filters' => ['range'],
                'columns' => ['fecha' => ['Fecha', 'text'], 'usuario' => ['Usuario', 'text'], 'registro' => ['Registro', 'text'], 'ip' => ['IP', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.company_id', $f['cid'])->where('l.event', 'deleted')
                    ->whereBetween(DB::raw('date(l.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw("date_format(l.created_at, '%d/%m/%Y %H:%i') fecha, u.name usuario, concat(substring_index(l.auditable_type, '\\\\', -1), ' #', l.auditable_id) registro, l.ip")->orderByDesc('l.created_at'),
            ],
            'paises-ciudades' => [
                'title' => 'Países, departamentos y ciudades', 'filters' => ['q'],
                'columns' => ['pais' => ['País', 'text'], 'departamento' => ['Departamento', 'text'], 'codigo' => ['Código DANE', 'text'], 'ciudad' => ['Ciudad', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::table('municipalities as m')->join('departments as d', 'd.code', '=', 'm.department_code')
                    ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('m.name', 'like', "%$v%")->orWhere('d.name', 'like', "%$v%")))
                    ->selectRaw("'Colombia' pais, d.name departamento, m.code codigo, m.name ciudad")->orderBy('d.name')->orderBy('m.name'),
            ],
            'historial-reportes' => [
                'title' => 'Historial de reportes generados', 'filters' => ['range'],
                'columns' => ['fecha' => ['Fecha', 'text'], 'usuario' => ['Usuario', 'text'], 'reporte' => ['Reporte', 'text'], 'filas' => ['Filas', 'int']],
                'totals' => [],
                'query' => fn ($f) => DB::table('report_exports as x')->leftJoin('users as u', 'u.id', '=', 'x.user_id')->where('x.company_id', $f['cid'])
                    ->whereBetween(DB::raw('date(x.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw("date_format(x.created_at, '%d/%m/%Y %H:%i') fecha, u.name usuario, x.title reporte, x.rows_count filas")->orderByDesc('x.created_at'),
            ],
        ];
    }

    private const PSIGN = "(CASE WHEN d.type IN ('ND', 'NA') THEN -1 ELSE 1 END)";

    // ---------------- Consultas base ----------------

    public static function sales(array $f, array $types = ['FV', 'NC']): Builder
    {
        return DB::table('sales_documents as d')->where('d.company_id', $f['cid'])->whereIn('d.type', $types)->where('d.status', '<>', 'annulled')
            ->whereBetween('d.date', [$f['from'], $f['to']])
            ->when($f['contact'] ?? null, fn ($q, $v) => $q->where('d.contact_id', $v))
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->where('d.seller_id', $v));
    }

    public static function salesItems(array $f): Builder
    {
        return self::sales($f)->join('sales_document_items as i', 'i.sales_document_id', '=', 'd.id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v));
    }

    public static function purchases(array $f, array $types = ['FC', 'DS', 'ND', 'NA']): Builder
    {
        return DB::table('purchase_documents as d')->where('d.company_id', $f['cid'])->whereIn('d.type', $types)->where('d.status', '<>', 'annulled')
            ->whereBetween('d.date', [$f['from'], $f['to']])
            ->when($f['contact'] ?? null, fn ($q, $v) => $q->where('d.contact_id', $v));
    }

    public static function purchaseItems(array $f): Builder
    {
        return self::purchases($f)->join('purchase_document_items as i', 'i.purchase_document_id', '=', 'd.id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v));
    }

    private static function receipts(array $f, string $type): Builder
    {
        return DB::table('receipts as r')->join('receipt_items as ri', 'ri.receipt_id', '=', 'r.id')->join('contacts as c', 'c.id', '=', 'r.contact_id')
            ->where('r.company_id', $f['cid'])->where('r.type', $type)->where('r.status', '<>', 'annulled')->whereBetween('r.date', [$f['from'], $f['to']])
            ->when($f['contact'], fn ($q, $v) => $q->where('r.contact_id', $v));
    }

    private static function products(array $f): Builder
    {
        return DB::table('products as p')->leftJoin('product_lines as l', 'l.id', '=', 'p.product_line_id')->where('p.company_id', $f['cid'])->where('p.stock_control', true)
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('p.id', $v))
            ->when($f['line'] ?? null, fn ($q, $v) => $q->where('p.product_line_id', $v));
    }

    private static function movements(array $f, string $source): Builder
    {
        return DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.company_id', $f['cid'])->where('m.document', 'like', $source.'-%')
            ->whereBetween('m.date', [$f['from'], $f['to']])->when($f['product'], fn ($q, $v) => $q->where('m.product_id', $v))
            ->selectRaw('m.document documento, m.date fecha, p.code codigo, p.name producto, m.quantity cantidad, m.total_cost costo')->orderBy('m.date');
    }

    private static function costCenterLines(array $f): Builder
    {
        return DB::table('journal_lines as jl')->join('cost_centers as cc', 'cc.id', '=', 'jl.cost_center_id')->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('jl.company_id', $f['cid'])->whereBetween('jl.date', [$f['from'], $f['to']])
            ->when($f['account'], fn ($q, $v) => $q->where('a.code', 'like', "$v%"));
    }

    /** Cartera por edades a la fecha de corte (cuentas por cobrar o por pagar). */
    private static function aging(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $table = $purchases ? 'purchase_documents' : 'sales_documents';
        $due = 'datediff(?, coalesce(d.due_date, d.date))';
        $bucket = fn ($cond) => "case when $cond then d.balance else 0 end";

        return DB::table("$table as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
            ->whereIn('d.type', $purchases ? ['FC', 'DS'] : ['FV'])->where('d.status', '<>', 'annulled')->where('d.balance', '>', 0)->where('d.date', '<=', $f['cutoff'])
            ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
            ->selectRaw("c.identification ident, c.name tercero, d.name documento, d.date fecha, coalesce(d.due_date, d.date) vence, greatest($due, 0) dias, d.total,
                {$bucket("$due <= 0")} sin_vencer, {$bucket("$due between 1 and 30")} d30, {$bucket("$due between 31 and 60")} d60,
                {$bucket("$due between 61 and 90")} d90, {$bucket("$due > 90")} mas90, d.balance saldo", array_fill(0, 6, $f['cutoff']))
            ->orderBy('c.name')->orderBy('d.date');
    }

    /** Flujo de efectivo por metodo indirecto a partir del libro auxiliar. */
    private static function cashFlow(array $f): array
    {
        $mov = fn (string $like, ?string $not = null) => (float) DB::table('ledger_lines')->where('company_id', $f['cid'])->whereBetween('date', [$f['from'], $f['to']])
            ->where('account_code', 'like', $like)->when($not, fn ($q) => $q->where('account_code', 'not like', $not))->sum(DB::raw('debit - credit'));
        $before = fn (string $like) => (float) DB::table('ledger_lines')->where('company_id', $f['cid'])->where('date', '<', $f['from'])
            ->where('account_code', 'like', $like)->sum(DB::raw('debit - credit'));

        $profit = -($mov('4%') + $mov('5%') + $mov('6%') + $mov('7%'));
        $operation = [
            'Variación en cuentas por cobrar (13)' => -$mov('13%'),
            'Variación en inventarios (14)' => -$mov('14%'),
            'Variación en otros activos (16-19)' => -($mov('16%') + $mov('17%') + $mov('18%') + $mov('19%')),
            'Variación en proveedores y cuentas por pagar (22-23)' => -($mov('22%') + $mov('23%')),
            'Variación en impuestos por pagar (24)' => -$mov('24%'),
            'Variación en obligaciones laborales (25)' => -$mov('25%'),
            'Variación en otros pasivos (26-29)' => -($mov('26%') + $mov('27%') + $mov('28%') + $mov('29%')),
        ];
        $investing = ['Variación en propiedad, planta y equipo (15)' => -$mov('15%'), 'Variación en inversiones (12)' => -$mov('12%')];
        $financing = ['Variación en obligaciones financieras (21)' => -$mov('21%'), 'Variación en patrimonio (31-34, 37-38)' => -($mov('3%') - $mov('36%'))];

        $rows = [['concepto' => 'ACTIVIDADES DE OPERACIÓN', 'valor' => null, 'bold' => true], ['concepto' => 'Utilidad (pérdida) del periodo', 'valor' => $profit]];
        foreach ($operation as $k => $v) {
            $rows[] = ['concepto' => $k, 'valor' => $v];
        }
        $op = $profit + array_sum($operation);
        $rows[] = ['concepto' => 'Efectivo neto de actividades de operación', 'valor' => $op, 'bold' => true];
        $rows[] = ['concepto' => 'ACTIVIDADES DE INVERSIÓN', 'valor' => null, 'bold' => true];
        foreach ($investing as $k => $v) {
            $rows[] = ['concepto' => $k, 'valor' => $v];
        }
        $rows[] = ['concepto' => 'Efectivo neto de actividades de inversión', 'valor' => array_sum($investing), 'bold' => true];
        $rows[] = ['concepto' => 'ACTIVIDADES DE FINANCIACIÓN', 'valor' => null, 'bold' => true];
        foreach ($financing as $k => $v) {
            $rows[] = ['concepto' => $k, 'valor' => $v];
        }
        $rows[] = ['concepto' => 'Efectivo neto de actividades de financiación', 'valor' => array_sum($financing), 'bold' => true];
        $net = $op + array_sum($investing) + array_sum($financing);
        $opening = $before('11%');
        $rows[] = ['concepto' => 'Aumento (disminución) neto del efectivo', 'valor' => $net, 'bold' => true];
        $rows[] = ['concepto' => 'Efectivo al inicio del periodo', 'valor' => $opening];
        $rows[] = ['concepto' => 'Efectivo al final del periodo', 'valor' => $opening + $net, 'bold' => true];
        $rows[] = ['concepto' => 'Efectivo según libro (cuenta 11)', 'valor' => $opening + $mov('11%')];

        return $rows;
    }

    // ---------------- Utilidades ----------------

    private static function itemSums(): string
    {
        return 'sum(i.quantity * '.self::SIGN.') cantidad, sum(i.subtotal * '.self::SIGN.') subtotal, sum(i.tax_total * '.self::SIGN.') impuestos, sum((i.subtotal + i.tax_total) * '.self::SIGN.') total';
    }

    private static function purchaseItemSums(): string
    {
        return 'sum(i.quantity * '.self::PSIGN.') cantidad, sum(i.subtotal * '.self::PSIGN.') subtotal, sum(i.tax_total * '.self::PSIGN.') impuestos, sum((i.subtotal + i.tax_total) * '.self::PSIGN.') total';
    }

    private static function docSums(): string
    {
        return 'sum(d.subtotal * '.self::SIGN.') subtotal, sum(d.tax_total * '.self::SIGN.') impuestos, sum(d.total * '.self::SIGN.') total';
    }

    private static function monthColumns(): array
    {
        $cols = [];
        foreach (self::MONTHS as $i => $m) {
            $cols['m'.($i + 1)] = [$m, 'money'];
        }

        return [...$cols, 'total' => ['Total', 'money']];
    }

    private static function monthSums(string $expr, string $date = 'd.date'): string
    {
        $parts = [];
        for ($m = 1; $m <= 12; $m++) {
            $parts[] = "sum(case when month($date) = $m then $expr else 0 end) m$m";
        }

        return implode(', ', $parts).", sum($expr) total";
    }

    private static function levelLength(int $level): int
    {
        return [1 => 1, 2 => 2, 3 => 4, 4 => 6, 5 => 8][$level] ?? 4;
    }
}
