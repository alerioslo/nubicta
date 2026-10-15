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
            'ventas-cliente' => [
                'title' => 'Ventas por cliente', 'filters' => ['seller', 'range', 'contact', 'nc', 'pos'], 'primary' => ['seller'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuentos por item'), 'cargos' => ['Cargo en totales', 'money'], 'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'links' => ['cliente' => '/terceros/{contact_id}', 'documentos' => '/reportes/movimiento-facturas-venta?tercero={contact_id}&desde={from}&hasta={to}'],
                'query' => fn ($f) => self::salesDocs($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.id contact_id, '.self::ident('c').' ident, '.self::branch('c').' sucursal, c.name cliente, count(*) documentos, '.self::salesDocSums())
                    ->groupBy('c.id', 'c.identification', 'c.dv', 'c.branch_office', 'c.name')->orderByRaw('cast(c.identification as unsigned)'),
            ],
            'ventas-producto' => [
                'title' => 'Ventas por producto', 'filters' => ['product', 'nc', 'range', 'group'], 'primary' => ['product'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesLines($f)
                    ->selectRaw('i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, '.self::salesItemSums())
                    ->groupBy('i.code')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'ventas-vendedor' => [
                'title' => 'Ventas por vendedor', 'filters' => ['seller', 'contact', 'range', 'nc'], 'primary' => ['seller'],
                'columns' => ['ident' => ['Identificación', 'text'], 'vendedor' => ['Nombre vendedor', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesDocs($f)->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                    ->selectRaw("s.id seller_id, s.identification ident, coalesce(s.name, 'Sin vendedor') vendedor, count(*) documentos, ".self::salesDocSums())
                    ->groupBy('s.id', 's.identification', 's.name')->orderBy('vendedor'),
            ],
            'ventas-cliente-producto' => [
                'title' => 'Ventas por cliente por producto', 'filters' => ['contact', 'product', 'group', 'range', 'nc'], 'primary' => ['contact'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'codigo' => ['Código producto', 'text'],
                    'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesLines($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw(self::ident('c').' ident, '.self::branch('c').' sucursal, c.name cliente, i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, '.self::salesItemSums())
                    ->groupBy('c.id', 'c.identification', 'c.dv', 'c.branch_office', 'c.name', 'i.code')->orderByRaw('cast(c.identification as unsigned)')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'ventas-vendedor-producto' => [
                'title' => 'Ventas por vendedor por producto', 'filters' => ['seller', 'product', 'group', 'range', 'nc'], 'primary' => ['seller'],
                'columns' => ['vendedor' => ['Nombre vendedor', 'text'], 'codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'grupo' => ['Grupo inventario', 'text'], 'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesLines($f, 'coalesce(i.seller_id, d.seller_id)')->leftJoin('sellers as s', 's.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)'))
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, ".self::salesItemSums())
                    ->groupBy('s.id', 's.name', 'i.code')->orderBy('vendedor')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'movimiento-facturas-venta' => [
                'title' => 'Movimiento ventas y compras', 'filters' => ['kind', 'contact', 'cost_center', 'range', 'creator'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Tercero'], 'kinds' => self::MOVEMENT_KINDS,
                'columns' => self::movementColumns(true),
                'totals' => ['cantidad', 'unitario', 'descuento', 'aiu', 'cargo1', 'cargo2', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::movement($f, $f['kind'] ? [$f['kind']] : array_keys(self::MOVEMENT_KINDS)),
            ],
            'movimiento-notas-venta' => [
                'title' => 'Movimiento de notas débito, crédito y nota débito (Ventas)', 'filters' => ['contact', 'cost_center', 'range', 'creator'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Tercero'],
                'columns' => self::movementColumns(false),
                'totals' => ['cantidad', 'unitario', 'descuento', 'aiu', 'cargo1', 'cargo2', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::movement($f, ['NC', 'ND']),
            ],
            'movimiento-recibos' => [
                'title' => 'Movimiento de recibos de caja/pagos', 'filters' => ['kind', 'contact', 'cost_center', 'range', 'creator'], 'primary' => ['kind'],
                'labels' => ['kind' => 'Tipo de transacción', 'contact' => 'Tercero'], 'kinds' => ['RC' => 'Recibos de caja', 'RP' => 'Recibos de pago'],
                'columns' => ['documento' => ['Documento', 'text'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'nombre' => ['Nombre', 'text'],
                    'centro' => ['Centro de costo', 'text'], 'fecha' => ['Fecha de elaboración', 'date'], 'forma_pago' => ['Forma de pago', 'text'], 'valor' => ['Valor', 'money'],
                    'anticipos' => ['Valor anticipos', 'money'], 'moneda' => ['Moneda', 'text']],
                'totals' => ['valor', 'anticipos'],
                'query' => fn ($f) => DB::table('receipts as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->leftJoin('payment_methods as pm', 'pm.id', '=', 'r.payment_method_id')
                    ->where('r.company_id', $f['cid'])->whereIn('r.type', $f['kind'] ? [$f['kind']] : ['RC', 'RP'])->where('r.status', '<>', 'annulled')
                    ->whereBetween('r.date', [$f['from'], $f['to']])->when($f['contact'], fn ($q, $v) => $q->where('r.contact_id', $v))
                    ->when($f['cost_center'], fn ($q) => $q->whereRaw('1 = 0'))->when($f['creator'], fn ($q, $v) => $q->where('r.created_by', $v))
                    ->selectRaw("r.id, r.name documento, c.identification ident, ".self::branch('c')." sucursal, c.name nombre, null centro, r.date fecha,
                        coalesce(pm.name, r.payment_name) forma_pago, r.total valor, 0 anticipos, 'COP' moneda")
                    ->orderBy('r.type')->orderByRaw("substring_index(r.name, '-', 2)")->orderBy('r.number')->orderBy('r.id'),
            ],
            'listado-productos' => [
                'title' => 'Listado de productos / Servicios', 'filters' => ['product', 'kind', 'status', 'group'], 'primary' => ['product'],
                'labels' => ['kind' => 'Saldo', 'status' => 'Estado', 'group' => 'Categoría'],
                'kinds' => ['con' => 'Con saldo', 'sin' => 'Sin saldo', 'todos' => 'Todos'], 'statuses' => ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'todos' => 'Todos'],
                'defaults' => ['kind' => 'con', 'status' => 'activo'],
                'columns' => ['tipo' => ['Tipo', 'text'], 'codigo' => ['Código', 'text'], 'nombre' => ['Nombre', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'categoria' => ['Categoría', 'text'], 'estado' => ['Estado', 'text'], 'impuesto1' => ['Impuesto cargo', 'text'], 'impuesto2' => ['Impuesto Cargo 2', 'text'],
                    'precio1' => ['Precio de venta 1', 'money'], 'descripcion' => ['Descripción larga', 'text'], 'incluido' => ['Es incluido', 'text'], 'inventariable' => ['Inventariable', 'text'],
                    'precio2' => ['Precio de venta 2', 'money'], 'precio3' => ['Precio de venta 3', 'money'], 'saldo' => ['Saldo cantidades', 'qty'],
                    'visible' => ['Visible en facturas de venta', 'text'], 'minimo' => ['Stock mínimo', 'qty']],
                'totals' => ['saldo'],
                'links' => ['codigo' => '/productos/{id}/editar'],
                'query' => function ($f) {
                    $price = fn (int $n) => "(select pp.value from product_prices pp join price_lists pl on pl.id = pp.price_list_id where pp.product_id = p.id and pl.position = $n limit 1)";
                    $tax = fn (int $n) => '(select t.name from product_tax pt join taxes t on t.id = pt.tax_id where pt.product_id = p.id and t.type not in '.self::WITHHOLDING_TYPES." order by t.id limit 1 offset $n)";

                    return DB::table('products as p')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')->where('p.company_id', $f['cid'])
                        ->when($f['product'], fn ($q, $v) => $q->where('p.id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                        ->when($f['kind'] === 'con', fn ($q) => $q->where('p.stock', '>', 0))->when($f['kind'] === 'sin', fn ($q) => $q->where('p.stock', '<=', 0))
                        ->when($f['status'] === 'activo', fn ($q) => $q->where('p.active', true))->when($f['status'] === 'inactivo', fn ($q) => $q->where('p.active', false))
                        ->selectRaw("p.id, case p.type when 'Service' then 'Servicio' when 'Combo' then 'Combo' else 'Producto' end tipo, p.code codigo, p.name nombre, p.reference referencia,
                            g.name categoria, if(p.active, 'Activo', 'Inactivo') estado, {$tax(0)} impuesto1, {$tax(1)} impuesto2, {$price(1)} precio1, nullif(p.description, '') descripcion,
                            if(p.tax_included, 'Si', 'NO') incluido, if(p.stock_control, 'Si', 'No') inventariable, {$price(2)} precio2, {$price(3)} precio3, p.stock saldo,
                            if(p.visible_in_sales, 'SI', 'NO') visible, p.min_stock minimo")
                        ->orderBy('p.code');
                },
            ],
            'ventas-centro-costo' => [
                'title' => 'Ventas por centro de costo', 'filters' => ['cost_center', 'contact', 'range', 'nc'], 'primary' => ['cost_center'],
                'columns' => ['codigo' => ['Código centro de costo', 'text'], 'centro' => ['Centro de costo', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuento'), 'cargos' => ['Cargo en totales', 'money'], 'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['documentos', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::salesDocs($f)->join('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
                    ->when($f['cost_center'], fn ($q, $v) => $q->where('d.cost_center_id', $v))
                    ->selectRaw('cc.code codigo, cc.name centro, count(*) documentos, '.self::salesDocSums())
                    ->groupBy('cc.id', 'cc.code', 'cc.name')->orderBy('cc.code'),
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
                'title' => 'Informe facturación de obsequios', 'filters' => ['range', 'contact', 'product'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'cantidad' => ['Cantidad', 'qty'],
                    'base' => ['Valor base IVA', 'money'], 'porcentaje' => ['Porcentaje IVA', 'pct'], 'iva' => ['Valor IVA obsequio', 'money'], 'asumido' => ['IVA asumido por', 'text'],
                    'ident' => ['Identificación', 'text'], 'cliente' => ['Nombre tercero', 'text'], 'sucursal' => ['Sucursal', 'text']],
                'totals' => ['cantidad', 'base', 'iva'],
                'query' => fn ($f) => self::salesItems($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.type', 'FV')
                    ->whereRaw('(i.price = 0 or i.discount >= i.quantity * i.price)')
                    ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                    ->selectRaw("d.name documento, i.code codigo, coalesce(p.name, i.description) producto, i.quantity cantidad, i.quantity * coalesce(p.average_cost, 0) base,
                        coalesce(i.tax_percentage, 0) porcentaje, i.quantity * coalesce(p.average_cost, 0) * coalesce(i.tax_percentage, 0) / 100 iva, 'Empresa' asumido,
                        ".self::ident('c').' ident, c.name cliente, '.self::branch('c').' sucursal')
                    ->orderBy('d.date')->orderBy('d.number'),
            ],
            'cotizaciones-vendedor' => [
                'title' => 'Cotizaciones por vendedor', 'filters' => ['seller', 'range'], 'primary' => ['seller'], 'labels' => ['seller' => 'Responsable'],
                'columns' => ['vendedor' => ['Responsable', 'text'], 'descartadas' => ['Cotizaciones descartadas', 'money'], 'aprobadas' => ['Cotizaciones aprobadas', 'money'],
                    'facturadas' => ['Cotizaciones facturadas', 'money'], 'sin_aprobar' => ['Cotizaciones sin aprobar', 'money']],
                'totals' => ['descartadas', 'aprobadas', 'facturadas', 'sin_aprobar'],
                'query' => function ($f) {
                    $billed = "exists (select 1 from sales_documents x where x.related_document_id = d.id and x.type = 'FV' and x.status <> 'annulled')";

                    return DB::table('sales_documents as d')->where('d.company_id', $f['cid'])->where('d.type', 'CT')->whereBetween('d.date', [$f['from'], $f['to']])
                        ->when($f['seller'], fn ($q, $v) => $q->where('d.seller_id', $v))->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                        ->selectRaw("coalesce(s.name, 'Sin responsable') vendedor,
                            sum(case when d.status = 'annulled' then d.total else 0 end) descartadas,
                            sum(case when d.status = 'approved' and not $billed then d.total else 0 end) aprobadas,
                            sum(case when d.status <> 'annulled' and $billed then d.total else 0 end) facturadas,
                            sum(case when d.status not in ('annulled', 'approved') and not $billed then d.total else 0 end) sin_aprobar")
                        ->groupBy('s.id', 's.name')->orderBy('vendedor');
                },
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
                'title' => 'Recibos de caja detallado por facturas', 'filters' => ['contact', 'payment', 'creator', 'range'], 'primary' => ['contact'],
                'columns' => ['recibo' => ['Comprobante', 'text'], 'fecha' => ['Fecha elaboración', 'date'], 'creador' => ['Creador', 'text'], 'ident' => ['Identificación', 'text'],
                    'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'cuenta' => ['Cuenta contable', 'text'], 'vence' => ['Vencimiento', 'text'],
                    'valor' => ['Valor', 'money'], 'forma' => ['Forma de pago', 'text']],
                'totals' => ['valor'],
                'links' => ['recibo' => '/documentos/recibos/{receipt_id}', 'cliente' => '/terceros/{contact_id}'],
                'query' => fn ($f) => self::receipts($f, 'RC')
                    ->leftJoin('sales_documents as sd', 'sd.id', '=', 'ri.sales_document_id')->leftJoin('users as u', 'u.id', '=', 'r.created_by')
                    ->leftJoin('accounts as a', 'a.id', '=', 'ri.account_id')
                    ->when($f['payment'], fn ($q, $v) => $q->where('r.payment_method_id', $v))->when($f['creator'], fn ($q, $v) => $q->where('r.created_by', $v))
                    ->selectRaw("r.id receipt_id, c.id contact_id, r.name recibo, r.date fecha, u.name creador, c.identification ident, ".self::branch('c')." sucursal, c.name cliente,
                        coalesce(a.code, (select g.account_code from ledger_lines g where g.company_id = r.company_id and g.document = r.name and g.credit > 0 and g.account_code like '13%' limit 1)) cuenta,
                        coalesce(nullif(concat_ws('-', ri.due_prefix, ri.due_number, ri.due_quote), ''), sd.name, ri.description) vence, ri.value valor, r.payment_name forma")
                    ->orderBy('r.date')->orderBy('r.number')->orderBy('ri.id'),
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
            'cxc-general' => [
                'title' => 'Cuentas por cobrar/pagar general por cliente/proveedor', 'filters' => ['contact', 'module'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Cliente', 'module' => 'Tipo'], 'column_picker' => true, 'narrow' => ['nombre'],
                'module_labels' => ['ventas' => 'Saldos por cobrar (Clientes)', 'compras' => 'Saldos por pagar (Proveedores)'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'sucursal' => ['Sucursal', 'text'],
                    'd30' => ['Vencido 1 a 30', 'money'], 'd60' => ['Vencido 31 a 60', 'money'], 'd90' => ['Vencido 61 a 90', 'money'], 'mas90' => ['Vencido más de 91', 'money'],
                    'por_vencer' => ['Saldo por vencer', 'money'], 'favor' => ['Saldo a favor', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['d30', 'd60', 'd90', 'mas90', 'por_vencer', 'favor', 'total'],
                'links' => ['nombre' => '@url'],
                'query' => fn ($f) => self::agingByContact($f),
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
                'title' => 'Compras por proveedor', 'filters' => ['range', 'contact', 'nc'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Proveedor', 'nc' => 'Incluye nota débito'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'proveedor' => ['Proveedor', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::purchaseValueColumns()],
                'totals' => ['documentos', 'bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchases($f, self::purchaseTypes($f))->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.identification ident, '.self::branch('c').' sucursal, c.name proveedor, count(*) documentos, '.self::purchaseDocSums())
                    ->groupBy('c.id', 'c.identification', 'c.branch_office', 'c.name')->orderByRaw('cast(c.identification as unsigned), c.identification'),
            ],
            'compras-producto' => [
                'title' => 'Compras por producto', 'filters' => ['range', 'product', 'group', 'nc'], 'primary' => ['product'],
                'labels' => ['nc' => 'Incluye nota crédito'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'grupo' => ['Grupo inventario', 'text'], 'factura' => ['Factura proveedor', 'text'], 'cantidad' => ['Cantidad', 'qty'], ...self::purchaseValueColumns()],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchaseProductLines($f)
                    ->selectRaw("p.code codigo, p.name producto, p.reference referencia, g.name grupo, nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '') factura, ".self::purchaseItemSums())
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.reference', 'g.name', 'd.supplier_prefix', 'd.supplier_number')->orderByRaw('cast(p.code as unsigned), p.code, min(d.date), min(d.id)'),
            ],
            'compras-producto-proveedor' => [
                'title' => 'Compras por producto por proveedor', 'filters' => ['range', 'product', 'group', 'contact', 'nc'], 'primary' => ['product'],
                'labels' => ['contact' => 'Proveedor', 'nc' => 'Incluye nota débito'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'proveedor' => ['Proveedor', 'text'], 'factura' => ['Factura proveedor', 'text'],
                    'cantidad' => ['Cantidad', 'qty'], 'unitario' => ['Valor unitario', 'money'], ...self::purchaseValueColumns()],
                'totals' => ['cantidad', 'unitario', 'bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchaseProductLines($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("p.code codigo, p.name producto, g.name grupo, c.identification ident, ".self::branch('c')." sucursal, c.name proveedor,
                        nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '') factura, i.price unitario, ".self::purchaseItemSums())
                    ->groupBy('p.id', 'p.code', 'p.name', 'g.name', 'c.id', 'c.identification', 'c.branch_office', 'c.name', 'd.supplier_prefix', 'd.supplier_number', 'i.price')
                    ->orderByRaw('cast(p.code as unsigned), p.code, cast(c.identification as unsigned), factura'),
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
                'title' => 'Informe costeo mensual', 'filters' => ['group', 'cutoff', 'product'], 'primary' => ['group'],
                'labels' => ['cutoff' => 'Fecha Corte', 'group' => 'Categoría'], 'links' => ['producto' => '/productos/{id}/editar'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'promedio' => ['Costo promedio', 'cost'], 'salidas' => ['Salida del inventario', 'qty'], 'costo' => ['Costos totales', 'money']],
                'totals' => ['promedio', 'salidas', 'costo'],
                'query' => function ($f) {
                    [$y, $m] = [(int) substr($f['cutoff'], 0, 4), (int) substr($f['cutoff'], 5, 2)];
                    $snapshot = DB::table('product_monthly_costs')->where('company_id', $f['cid'])->where('year', $y)->where('month', $m)->exists();
                    if ($snapshot) {
                        return DB::table('product_monthly_costs as k')->leftJoin('products as p', 'p.id', '=', 'k.product_id')
                            ->where('k.company_id', $f['cid'])->where('k.year', $y)->where('k.month', $m)
                            ->when($f['product'], fn ($q, $v) => $q->where('k.product_id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                            ->selectRaw('p.id, k.code codigo, p.name producto, p.reference referencia, k.average_cost promedio, k.quantity_out salidas, k.total_cost costo')
                            ->orderByRaw('cast(k.code as unsigned), k.code');
                    }
                    $start = sprintf('%04d-%02d-01', $y, $m);

                    return DB::table('sales_document_items as i')->join('sales_documents as d', 'd.id', '=', 'i.sales_document_id')->join('products as p', 'p.id', '=', 'i.product_id')
                        ->where('d.company_id', $f['cid'])->whereIn('d.type', ['FV', 'ND'])->where('d.status', '<>', 'annulled')->where('p.stock_control', true)
                        ->whereBetween('d.date', [$start, date('Y-m-t', strtotime($start))])
                        ->when($f['product'], fn ($q, $v) => $q->where('p.id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                        ->selectRaw('p.id, p.code codigo, p.name producto, p.reference referencia, p.average_cost promedio, sum(i.quantity) salidas, sum(i.quantity) * p.average_cost costo')
                        ->groupBy('p.id', 'p.code', 'p.name', 'p.reference', 'p.average_cost')->orderByRaw('cast(p.code as unsigned), p.code');
                },
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

            // ---------------- Nomina (Reportes de nómina) ----------------
            'prima-servicios' => [
                'title' => 'Prima de servicios', 'filters' => ['range', 'contact', 'cost_center'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'contrato' => ['No. Contrato', 'text'], 'sueldo' => ['Sueldo', 'money'],
                    'origen' => ['Origen', 'text'], 'centro' => ['Centro de costo', 'text'], 'dias_lnr' => ['Días Lic. no remunerada', 'qty'], 'valor_lnr' => ['Valor Lic. no remunerada', 'money'],
                    'acumulado' => ['Acumulado', 'money'], 'promedio' => ['Salario promedio', 'money'], 'dias' => ['Días trabajados', 'qty'], 'prima' => ['Valor prima', 'money'],
                    'anticipo' => ['Anticipo', 'money'], 'retencion' => ['Retención', 'money'], 'editado' => ['Valor editado', 'text'], 'neto' => ['Valor neto', 'money']],
                'totals' => ['prima', 'anticipo', 'retencion', 'neto'],
                'query' => fn ($f) => self::payrollDocs($f)->join('payroll_runs as r', 'r.id', '=', 'pd.payroll_run_id')->where('r.kind', 'prima')
                    ->selectRaw("c.identification ident, c.name nombre, pd.contract_number contrato, pd.salary sueldo, 'Detallados' origen, cc.name centro,
                        0 dias_lnr, 0 valor_lnr, ".self::payrollItem('prima', 'base * quantity / 30')." acumulado, ".self::payrollItem('prima', 'base')." promedio,
                        pd.worked_days dias, ".self::payrollItem('prima')." prima, 0 anticipo, ".self::payrollItem('retefuente')." retencion, 'No' editado, pd.net neto")
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period'),
            ],
            'provisiones' => [
                'title' => 'Provisiones', 'filters' => ['range', 'contact', 'cost_center'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'contrato' => ['No contrato', 'text'], 'cesantias' => ['Cesantías', 'money'],
                    'intereses' => ['Intereses sobre cesantías', 'money'], 'prima' => ['Prima', 'money'], 'vacaciones' => ['Vacaciones', 'money'], 'total' => ['Total Provisiones', 'money']],
                'totals' => ['cesantias', 'intereses', 'prima', 'vacaciones', 'total'],
                'query' => fn ($f) => self::payrollDocs($f)->leftJoin('payroll_items as i', fn ($j) => $j->on('i.payroll_document_id', '=', 'pd.id')->where('i.type', 'provision'))
                    ->groupBy('e.id', 'c.identification', 'c.name', 'e.contract_number')
                    ->selectRaw("c.identification ident, c.name nombre, e.contract_number contrato,
                        coalesce(sum(if(i.concept = 'prov_cesantias', i.value, 0)), 0) cesantias, coalesce(sum(if(i.concept = 'prov_intereses', i.value, 0)), 0) intereses,
                        coalesce(sum(if(i.concept = 'prov_prima', i.value, 0)), 0) prima, coalesce(sum(if(i.concept = 'prov_vacaciones', i.value, 0)), 0) vacaciones,
                        coalesce(sum(i.value), 0) total")
                    ->orderByRaw('cast(c.identification as unsigned)'),
            ],
            'acumulados' => [
                'title' => 'Acumulados', 'filters' => ['range', 'contact', 'kind', 'cost_center', 'q'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado', 'kind' => 'Acumulado', 'q' => 'Novedad'],
                'kinds' => ['detallados' => 'Detallados', 'iniciales' => 'Iniciales'], 'defaults' => ['kind' => 'detallados'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre empleado', 'text'], 'contrato' => ['No contrato', 'text'], 'periodo' => ['Periodo', 'text'],
                    'mes' => ['Mes', 'text'], 'anio' => ['Año', 'text'], 'centro' => ['Centro de costo', 'text'], 'origen' => ['Origen', 'text'], 'novedad' => ['Novedad', 'text'],
                    'tipo' => ['Tipo', 'text'], 'cantidad' => ['Horas/Días', 'qty'], 'valor' => ['Valor total', 'money']],
                'totals' => ['cantidad', 'valor'],
                'query' => fn ($f) => self::payrollDocs($f)->join('payroll_items as i', 'i.payroll_document_id', '=', 'pd.id')->whereIn('i.type', ['earning', 'deduction'])
                    ->when($f['kind'] === 'iniciales', fn ($q) => $q->whereRaw('1 = 0'))->when($f['q'], fn ($q, $v) => $q->where('i.label', 'like', "%$v%"))
                    ->selectRaw("c.identification ident, c.name nombre, pd.contract_number contrato, coalesce(e.payment_frequency, 'Mensual') periodo,
                        cast(substr(pd.period, 6, 2) as unsigned) mes, cast(left(pd.period, 4) as unsigned) anio, cc.name centro, 'Detallados' origen, i.label novedad,
                        if(i.type = 'earning', 'Ingreso', 'Deducción') tipo, i.quantity cantidad, i.value valor")
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period')
                    ->orderByRaw("if(field(i.concept, 'sueldo', 'auxilio_transporte', 'salud', 'pension') = 0, 99, field(i.concept, 'sueldo', 'auxilio_transporte', 'salud', 'pension')), i.id"),
            ],
            'seguridad-social' => [
                'title' => 'Seguridad Social y Parafiscales', 'filters' => ['range', 'contact'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['empleado' => ['Empleado', 'text'], 'nomina' => ['Nómina', 'text'], 'ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'],
                    'contrato' => ['No contrato', 'text'], 'eps' => ['Fondo de salud', 'text'], 'devengado' => ['Total devengado Seguridad Social', 'money'],
                    'ibc_pension_emp' => ['IBC pensión empresa', 'money'], 'ibc_salud_emp' => ['IBC salud empresa', 'money'], 'exonerado' => ['Exonerado', 'text'],
                    'ibc_salud' => ['IBC salud empleado', 'money'], 'salud' => ['Aporte salud empleado', 'money'], 'salud_emp' => ['Aporte salud empresa', 'money'],
                    'afp' => ['Fondo de pensión', 'text'], 'ibc_pension' => ['IBC pensión empleado', 'money'], 'pension' => ['Aporte pensión empleado', 'money'],
                    'pension_pub' => ['Aporte pensión Publico empleado', 'money'], 'pension_emp' => ['Aporte pensión empresa', 'money'],
                    'pension_pub_emp' => ['Aporte pensión Publico empresa', 'money'], 'fsp' => ['Fondo de Solidaridad Pensional (FSP)', 'money'], 'arl' => ['ARL', 'text'],
                    'ibc_arl' => ['IBC ARL', 'money'], 'arl_pct' => ['Porcentaje ARL', 'qty'], 'arl_emp' => ['ARL Empresa', 'money'], 'ibc_para' => ['IBC Parafiscales', 'money'],
                    'exonerado_para' => ['Exonerado Parafiscales', 'text'], 'ccf' => ['Caja de compensación', 'money'], 'icbf' => ['ICBF', 'money'], 'sena' => ['SENA', 'money'],
                    'periodo' => ['Periodo', 'date']],
                'totals' => ['devengado', 'ibc_pension_emp', 'ibc_salud_emp', 'ibc_salud', 'salud', 'salud_emp', 'ibc_pension', 'pension', 'pension_pub', 'pension_emp',
                    'pension_pub_emp', 'fsp', 'ibc_arl', 'arl_pct', 'arl_emp', 'ibc_para', 'ccf', 'icbf', 'sena'],
                'query' => fn ($f) => self::payrollDocs($f)->leftJoin('contacts as ce', 'ce.id', '=', 'e.eps_contact_id')->leftJoin('contacts as cp', 'cp.id', '=', 'e.pension_contact_id')
                    ->leftJoin('contacts as ca', 'ca.id', '=', 'e.arl_contact_id')
                    ->selectRaw(implode(', ', ["cast(e.id as char) empleado, cast(pd.id as char) nomina, c.identification ident, c.name nombre, pd.contract_number contrato",
                        'coalesce(e.eps, ce.name) eps', self::payrollItem(['salud_empresa', 'pension_empresa'], 'base', 'max').' devengado',
                        self::payrollItem('pension_empresa', 'base').' ibc_pension_emp', self::payrollItem('salud_empresa', 'base').' ibc_salud_emp', "'No' exonerado",
                        self::payrollItem('salud', 'base').' ibc_salud', self::payrollItem('salud').' salud', self::payrollItem('salud_empresa').' salud_emp',
                        'coalesce(e.pension_fund, cp.name) afp', self::payrollItem('pension', 'base').' ibc_pension', self::payrollItem('pension').' pension', '0 pension_pub',
                        self::payrollItem('pension_empresa').' pension_emp', '0 pension_pub_emp', self::payrollItem('fsp').' fsp', 'coalesce(e.arl, ca.name) arl',
                        self::payrollItem('arl', 'base').' ibc_arl', 'elt(greatest(coalesce(e.arl_class, 1), 1), 0.522, 1.044, 2.436, 4.35, 6.96) arl_pct',
                        self::payrollItem('arl').' arl_emp', self::payrollItem('ccf', 'base').' ibc_para', "'No' exonerado_para", self::payrollItem('ccf').' ccf',
                        self::payrollItem('icbf').' icbf', self::payrollItem('sena').' sena', "date(concat(pd.period, '-', least(30, day(last_day(concat(pd.period, '-01')))))) periodo"]))
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period'),
            ],

            // ---------------- Nomina (versiones anteriores) ----------------
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
    /** Saldos por tercero en rangos de vencimiento a hoy; Total = vencido + por vencer - saldo a favor (notas y pagos a favor). */
    private static function agingByContact(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $days = 'datediff(curdate(), d.due_date)';
        $sum = fn ($cond) => "round(sum(case when d.balance > 0 and $cond then d.balance else 0 end), 2)";
        $url = $purchases ? '/compras/proveedores/' : '/ventas/clientes/';

        $inner = DB::table(($purchases ? 'purchase_documents' : 'sales_documents').' as d')->where('d.company_id', $f['cid'])
            ->whereIn('d.type', $purchases ? ['FC', 'DS', 'ND', 'SI', 'NA'] : ['FV', 'ND', 'SI', 'NC'])->where('d.status', '<>', 'annulled')->where('d.balance', '<>', 0)
            ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
            ->groupBy('d.contact_id')
            ->selectRaw("d.contact_id, {$sum("$days between 1 and 30")} d30, {$sum("$days between 31 and 60")} d60, {$sum("$days between 61 and 90")} d90,
                {$sum("$days > 90")} mas90, {$sum("(d.due_date is null or $days <= 0)")} por_vencer, round(sum(case when d.balance < 0 then -d.balance else 0 end), 2) favor");

        return DB::query()->fromSub($inner, 'b')->join('contacts as c', 'c.id', '=', 'b.contact_id')
            ->selectRaw("c.identification ident, c.name nombre, coalesce(c.branch_office, 0) sucursal, b.d30, b.d60, b.d90, b.mas90, b.por_vencer, b.favor,
                round(b.d30 + b.d60 + b.d90 + b.mas90 + b.por_vencer - b.favor, 2) total, concat('$url', c.id) url")
            ->orderByRaw('cast(c.identification as unsigned)')->orderBy('c.identification')->orderBy('c.branch_office');
    }

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

    // ---------------- Ventas como en Siigo ----------------

    private const WITHHOLDING_TYPES = "('Retefuente', 'ReteIVA', 'ReteICA', 'Autorretencion')";

    /** Facturas y notas debito de venta y, si el filtro "Incluye nota credito" esta en Si, notas credito restando. */
    public static function salesDocs(array $f): Builder
    {
        return self::sales([...$f, 'seller' => null], ($f['nc'] ?? true) ? ['FV', 'ND', 'NC'] : ['FV', 'ND'])
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->where('d.seller_id', $v))
            ->when($f['pos'] ?? false, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Items de venta con producto y grupo de inventario. */
    private static function salesLines(array $f, string $seller = 'd.seller_id'): Builder
    {
        return self::salesDocs([...$f, 'seller' => null])->join('sales_document_items as i', 'i.sales_document_id', '=', 'd.id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('account_groups as ag', 'ag.id', '=', 'p.account_group_id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v))
            ->when($f['group'] ?? null, fn ($q, $v) => $q->where('p.account_group_id', $v))
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->whereRaw("$seller = ?", [$v]));
    }

    private static function salesValueColumns(string $discount): array
    {
        return ['bruto' => ['Valor bruto', 'money'], 'descuento' => [$discount, 'money'], 'subtotal' => ['Subtotal', 'money'],
            'cargo' => ['Impuesto cargo', 'money'], 'retencion' => ['Impuesto retención', 'money']];
    }

    private static function salesDocSums(): string
    {
        $s = self::SIGN;

        return "sum((d.subtotal + d.discount) * $s) bruto, sum(d.discount * $s) descuento, sum(d.subtotal * $s) subtotal, sum(d.tax_total * $s) cargo,
            sum(d.withholding_total * $s) retencion, 0 cargos, 0 descuentos_totales, sum(d.total * $s) total";
    }

    private static function salesItemSums(): string
    {
        $s = self::SIGN;
        $ret = '(select coalesce(sum(t.value), 0) from sales_document_item_taxes t where t.sales_document_item_id = i.id and t.type in '.self::WITHHOLDING_TYPES.')';

        return "sum(i.quantity * $s) cantidad, sum((i.subtotal + i.discount) * $s) bruto, sum(i.discount * $s) descuento, sum(i.subtotal * $s) subtotal,
            sum(i.tax_total * $s) cargo, sum($ret * $s) retencion, sum(i.total * $s) total";
    }

    // ---------------- Movimiento de documentos (reportes 5397 y 5467 de Siigo) ----------------

    public const MOVEMENT_KINDS = ['DS' => 'Documento soporte', 'FC' => 'Factura de compra', 'FV' => 'Factura de venta'];

    private const MOVEMENT_LABELS = ['DS' => 'Documento soporte', 'FC' => 'Factura de compra', 'FV' => 'Factura de venta', 'NC' => 'Nota crédito', 'ND' => 'Nota débito', 'NA' => 'Nota de ajuste'];

    private static function movementColumns(bool $withClass): array
    {
        return [
            ...($withClass ? ['clase' => ['Tipo transacción', 'text']] : []),
            'comprobante' => ['Número comprobante', 'text'], 'consecutivo' => ['Consecutivo', 'text'], 'factura_proveedor' => ['Factura proveedor', 'text'],
            ...($withClass ? [] : ['relacionado' => ['Comprobante relacionado', 'text']]),
            'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'tercero' => ['Nombre tercero', 'text'], 'centro' => ['Centro costo', 'text'],
            'creacion' => ['Fecha creación', 'date'], 'modificacion' => ['Fecha modificación', 'date'], 'fecha' => ['Fecha elaboración', 'date'],
            'contacto' => ['Nombre contacto', 'text'], 'correo' => ['Correo electrónico', 'text'], 'registro' => ['Tipo de registro', 'text'], 'clasificacion' => ['Tipo clasificación', 'text'],
            'codigo' => ['Código', 'text'], 'nombre' => ['Nombre', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'bodega' => ['Bodega', 'text'],
            'vendedor_ident' => ['Identificación Vendedor', 'text'], 'vendedor' => ['Nombre vendedor', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'unitario' => ['Valor unitario', 'money'],
            'descuento' => ['Valor desc.', 'money'], 'aiu' => ['Base AIU', 'money'], 'cargo1_nombre' => ['Impuesto cargo', 'text'], 'cargo1' => ['Valor Impuesto Cargo', 'money'],
            'cargo2_nombre' => ['Impuesto Cargo 2', 'text'], 'cargo2' => ['Valor Impuesto Cargo 2', 'money'], 'retencion_nombre' => ['Impuesto retención', 'text'],
            'retencion' => ['Valor Impuesto Retención', 'money'], 'base_retencion' => ['Base retención (ICA/IVA)', 'money'], 'cargos' => ['Cargo en totales', 'money'],
            'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money'], 'moneda' => ['Moneda', 'text'], 'tasa' => ['Tasa de cambio', 'money'],
            'forma_pago' => ['Forma pago', 'text'], 'vence' => ['Fecha vencimiento', 'date'], 'observaciones' => ['Observaciones', 'text'],
        ];
    }

    /** Lineas de producto (tipo de registro 0) y formas de pago (3) de ventas y compras, ordenadas como Siigo. */
    private static function movement(array $f, array $types): Builder
    {
        $parts = [];
        $sales = array_values(array_intersect($types, ['FV', 'NC', 'ND']));
        $purchases = array_values(array_intersect($types, ['FC', 'DS', 'NA']));
        foreach (['sales' => $sales, 'purchases' => $purchases] as $module => $list) {
            if (! $list) {
                continue;
            }
            $p = $module === 'sales';
            [$docs, $items, $taxes, $pays, $fk, $tfk] = $p
                ? ['sales_documents', 'sales_document_items', 'sales_document_item_taxes', 'sales_document_payments', 'sales_document_id', 'sales_document_item_id']
                : ['purchase_documents', 'purchase_document_items', 'purchase_document_item_taxes', 'purchase_document_payments', 'purchase_document_id', 'purchase_document_item_id'];
            $base = fn () => DB::table("$docs as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')
                ->leftJoin('document_types as dt', 'dt.id', '=', 'd.document_type_id')->leftJoin('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
                ->where('d.company_id', $f['cid'])->whereIn('d.type', $list)->where('d.status', '<>', 'annulled')->whereBetween('d.date', [$f['from'], $f['to']])
                ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))->when($f['cost_center'], fn ($q, $v) => $q->where('d.cost_center_id', $v))
                ->when($f['creator'], fn ($q, $v) => $q->where('d.created_by', $v));
            $class = 'case d.type '.implode(' ', array_map(fn ($t) => "when '$t' then '".self::MOVEMENT_LABELS[$t]."'", $list)).' end';
            $siigoType = $p ? "if(d.type = 'ND', 'DF', d.type)" : 'd.type';
            $person = "(select trim(concat(cp.first_name, ' ', coalesce(cp.last_name, ''))) from contact_people cp where cp.contact_id = c.id order by cp.id limit 1)";
            $email = '(select cp.email from contact_people cp where cp.contact_id = c.id order by cp.id limit 1)';
            $common = "$class clase, d.type sort_type, concat($siigoType, '-', coalesce(dt.code, '1')) comprobante, cast(d.number as char) consecutivo,
                ".($p ? 'null' : "nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '')")." factura_proveedor, d.related_name relacionado,
                c.identification ident, ".self::branch('c')." sucursal, c.name tercero, cc.name centro, d.created_at creacion, null modificacion, d.date fecha,
                coalesce($person, c.name) contacto, coalesce($email, c.email) correo, d.currency moneda, 0 tasa, nullif(d.observations, '') observaciones, d.id doc_id";
            $tax = fn (string $what, bool $ret, int $offset) => "(select t.$what from $taxes t where t.$tfk = i.id and t.type ".($ret ? '' : 'not ').'in '.self::WITHHOLDING_TYPES." order by t.id limit 1 offset $offset)";
            $ret = "coalesce((select sum(t.value) from $taxes t where t.$tfk = i.id and t.type in ".self::WITHHOLDING_TYPES.'), 0)';
            $parts[] = $base()->join("$items as i", "i.$fk", '=', 'd.id')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'i.warehouse_id')
                ->when($p, fn ($q) => $q->leftJoin('sellers as s', 's.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)')))
                ->selectRaw("$common, 0 registro, ".($p ? "'Producto'" : "case i.item_type when 'Account' then 'Gasto' when 'FixedAsset' then 'Activo fijo' else 'Producto' end")." clasificacion,
                    i.code codigo, coalesce(p.name, i.description) nombre, p.reference referencia, w.name bodega, ".($p ? 's.identification' : 'null').' vendedor_ident, '.($p ? 's.name' : 'null')." vendedor,
                    i.quantity cantidad, i.price unitario, i.discount descuento, 0 aiu, {$tax('name', false, 0)} cargo1_nombre, coalesce({$tax('value', false, 0)}, 0) cargo1,
                    {$tax('name', false, 1)} cargo2_nombre, coalesce({$tax('value', false, 1)}, 0) cargo2, {$tax('name', true, 0)} retencion_nombre, $ret retencion,
                    null base_retencion, 0 cargos, 0 descuentos_totales, ".($p ? 'i.total' : "i.subtotal + i.tax_total - $ret")." total, null forma_pago, d.due_date vence, i.position orden");
            $parts[] = $base()->join("$pays as pp", "pp.$fk", '=', 'd.id')
                ->selectRaw("$common, if(d.type = 'ND' and pp.due_date is not null, 4, 3) registro, null clasificacion, null codigo, null nombre, null referencia, null bodega, null vendedor_ident, null vendedor,
                    null cantidad, null unitario, null descuento, null aiu, null cargo1_nombre, null cargo1, null cargo2_nombre, null cargo2, null retencion_nombre, null retencion,
                    null base_retencion, null cargos, null descuentos_totales, pp.value total, if(d.type = 'ND' and pp.due_date is not null, null, pp.name) forma_pago,
                    pp.due_date vence, 10000 + pp.id orden");
        }
        $union = array_shift($parts);
        foreach ($parts as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 'm')->select('m.*')
            ->orderByRaw("field(m.sort_type, 'DS', 'FC', 'FV', 'NA', 'NC', 'ND')")->orderBy('m.comprobante')->orderByDesc('m.fecha')->orderBy('m.creacion')->orderByRaw('cast(m.consecutivo as unsigned)')->orderBy('m.doc_id')->orderBy('m.orden');
    }

    /** Identificacion con digito de verificacion, como la muestra Siigo (12345678-9). */
    private static function ident(string $alias): string
    {
        return "concat($alias.identification, if($alias.dv is null or $alias.dv = '', '', concat('-', $alias.dv)))";
    }

    private static function branch(string $alias): string
    {
        return "if(coalesce($alias.branch_office, 0) = 0, '-', $alias.branch_office)";
    }

    // ---------------- Utilidades ----------------

    private static function itemSums(): string
    {
        return 'sum(i.quantity * '.self::SIGN.') cantidad, sum(i.subtotal * '.self::SIGN.') subtotal, sum(i.tax_total * '.self::SIGN.') impuestos, sum((i.subtotal + i.tax_total) * '.self::SIGN.') total';
    }

    private static function purchaseItemSums(): string
    {
        $ret = 'coalesce((select sum(t.value) from purchase_document_item_taxes t where t.purchase_document_item_id = i.id and t.type in '.self::WITHHOLDING_TYPES.'), 0)';

        return 'sum(i.quantity * '.self::PSIGN.') cantidad, sum((i.subtotal + i.discount) * '.self::PSIGN.') bruto, sum(i.discount * '.self::PSIGN.') descuento, '
            .'sum(i.subtotal * '.self::PSIGN.') subtotal, sum(i.tax_total * '.self::PSIGN.') impuestos, sum('.$ret.' * '.self::PSIGN.') retenciones, '
            .'sum((i.subtotal + i.tax_total - '.$ret.') * '.self::PSIGN.') total';
    }

    private static function purchaseDocSums(): string
    {
        return 'sum((d.subtotal + d.discount) * '.self::PSIGN.') bruto, sum(d.discount * '.self::PSIGN.') descuento, sum(d.subtotal * '.self::PSIGN.') subtotal, '
            .'sum(d.tax_total * '.self::PSIGN.') impuestos, sum(d.withholding_total * '.self::PSIGN.') retenciones, sum(d.total * '.self::PSIGN.') total';
    }

    private static function purchaseValueColumns(): array
    {
        return ['bruto' => ['Valor bruto', 'money'], 'descuento' => ['Descuento', 'money'], 'subtotal' => ['Subtotal', 'money'],
            'impuestos' => ['Impuesto cargo', 'money'], 'retenciones' => ['Impuesto retención', 'money'], 'total' => ['Total', 'money']];
    }

    /** Compras de Siigo: FC y DS, mas las notas (devoluciones) cuando el filtro de notas esta activo. */
    private static function purchaseTypes(array $f): array
    {
        return $f['nc'] ? ['FC', 'DS', 'ND', 'NA'] : ['FC', 'DS'];
    }

    private static function purchaseProductLines(array $f): Builder
    {
        return self::purchases($f, self::purchaseTypes($f))->join('purchase_document_items as i', 'i.purchase_document_id', '=', 'd.id')
            ->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')
            ->where('i.item_type', 'Product')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v))
            ->when($f['group'] ?? null, fn ($q, $v) => $q->where('p.account_group_id', $v));
    }

    /** Comprobantes de nómina del rango por mes (Siigo filtra por periodo, no por día). */
    private static function payrollDocs(array $f)
    {
        return DB::table('payroll_documents as pd')->join('employees as e', 'e.id', '=', 'pd.employee_id')->join('contacts as c', 'c.id', '=', 'e.contact_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'e.cost_center_id')->where('pd.company_id', $f['cid'])
            ->whereBetween('pd.period', [substr($f['from'], 0, 7), substr($f['to'], 0, 7)])
            ->when($f['contact'], fn ($q, $v) => $q->where('e.contact_id', $v))->when($f['cost_center'], fn ($q, $v) => $q->where('e.cost_center_id', $v));
    }

    private static function payrollItem(string|array $concepts, string $field = 'value', string $agg = 'sum'): string
    {
        $in = implode(', ', array_map(fn ($c) => "'$c'", (array) $concepts));

        return "coalesce((select $agg(x.$field) from payroll_items x where x.payroll_document_id = pd.id and x.concept in ($in)), 0)";
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
