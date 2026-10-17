<?php

/* Catalogo de reportes. url = pantalla del ERP que ya lo cubre. */
return [
    ['section' => 'Favoritos', 'groups' => [
        ['name' => 'Favoritos', 'reports' => [
            ['name' => 'Compras por proveedor', 'url' => '/reportes/compras-proveedor'],
            ['name' => 'Movimiento facturas de compra', 'url' => '/reportes/movimiento-facturas-venta'],
            ['name' => 'Movimiento auxiliar por cuenta contable', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Balance de prueba general', 'url' => '/contabilidad/balance-prueba'],
        ]],
    ]],
    ['section' => 'Historial de descargas', 'groups' => [
        ['name' => 'Historial de descargas', 'reports' => [
            ['name' => 'Historial de reportes generados', 'url' => '/reportes/historial-reportes'],
        ]],
    ]],
    ['section' => 'Ventas', 'groups' => [
        ['name' => 'Ventas', 'reports' => [
            ['name' => 'Ventas por cliente', 'url' => '/reportes/ventas-cliente'],
            ['name' => 'Ventas por producto', 'url' => '/reportes/ventas-producto'],
            ['name' => 'Ventas por vendedor', 'url' => '/reportes/ventas-vendedor'],
            ['name' => 'Ventas por cliente por producto', 'url' => '/reportes/ventas-cliente-producto'],
            ['name' => 'Ventas por vendedor por producto', 'url' => '/reportes/ventas-vendedor-producto'],
            ['name' => 'Ventas por centro de costo', 'url' => '/reportes/ventas-centro-costo'],
            ['name' => 'Comparativo de ventas por mes', 'url' => '/reportes/comparativo-ventas-mes'],
            ['name' => 'Comparativo de ventas por producto', 'url' => '/reportes/comparativo-ventas-producto'],
            ['name' => 'Comparativo de ventas por vendedor', 'url' => '/reportes/comparativo-ventas-vendedor'],
            ['name' => 'Informe facturación de obsequios', 'url' => '/reportes/facturacion-obsequios'],
            ['name' => 'Impresión y envío masivo de comprobantes', 'url' => '/ventas/envio-masivo'],
        ]],
        ['name' => 'Cotizaciones', 'reports' => [
            ['name' => 'Cotizaciones por vendedor', 'url' => '/reportes/cotizaciones-vendedor'],
        ]],
        ['name' => 'Proveedor tecnológico (PT)', 'reports' => [
            ['name' => 'Informes facturación electrónica', 'url' => '/reportes/facturacion-electronica'],
        ]],
        ['name' => 'Facturas recurrentes', 'reports' => [
            ['name' => 'Seguimiento de facturas recurrentes', 'url' => '/ventas/facturas-recurrentes'],
        ]],
        ['name' => 'Recibos de caja', 'reports' => [
            ['name' => 'Recibos de caja detallado por facturas', 'url' => '/reportes/recibos-caja-facturas'],
        ]],
        ['name' => 'Movimiento de documentos de ventas', 'reports' => [
            ['name' => 'Movimiento facturas de venta / Ingresos', 'url' => '/reportes/movimiento-facturas-venta'],
            ['name' => 'Movimiento notas crédito y débito (ventas)', 'url' => '/reportes/movimiento-notas-venta'],
        ]],
    ]],
    ['section' => 'Clientes y proveedores', 'groups' => [
        ['name' => 'Cuanto me deben (clientes) o Cuanto estoy debiendo (proveedores)', 'reports' => [
            ['name' => 'Cuentas por cobrar/pagar general por cliente/proveedor', 'url' => '/reportes/cxc-general'],
            ['name' => 'Cuentas por cobrar/pagar detallada por documento', 'url' => '/reportes/cartera-detallada'],
            ['name' => 'Movimiento de cuentas por cobrar/pagar por documento', 'url' => '/reportes/cartera-movimiento'],
            ['name' => 'Movimiento de recibos de caja/pago', 'url' => '/reportes/movimiento-recibos'],
            ['name' => 'Cuentas por cobrar con corte a una fecha', 'url' => '/reportes/cxc-corte'],
        ]],
        ['name' => 'Clientes', 'reports' => [
            ['name' => 'Búsqueda de cliente, proveedor u otro', 'url' => '/reportes/busqueda-terceros'],
            ['name' => 'Búsqueda avanzada contacto de cliente, proveedor u otro', 'url' => '/reportes/busqueda-contactos'],
        ]],
    ]],
    ['section' => 'Compras y gastos', 'groups' => [
        ['name' => 'Compras y gastos', 'reports' => [
            ['name' => 'Compras por proveedor', 'url' => '/reportes/compras-proveedor'],
            ['name' => 'Compras por producto', 'url' => '/reportes/compras-producto'],
            ['name' => 'Compras por producto por proveedor', 'url' => '/reportes/compras-producto-proveedor'],
            ['name' => 'Cruce orden de compra vs factura de compra', 'url' => '/reportes/cruce-orden-compra'],
        ]],
        ['name' => 'Movimiento de documentos compras', 'reports' => [
            ['name' => 'Movimiento facturas de compra', 'url' => '/reportes/movimiento-facturas-venta'],
            ['name' => 'Movimiento notas débito', 'url' => '/reportes/movimiento-notas-venta'],
        ]],
    ]],
    ['section' => 'Productos y servicios', 'groups' => [
        ['name' => 'Gestión de costos', 'reports' => [
            ['name' => 'Informe costeo mensual', 'url' => '/reportes/costeo-mensual'],
            ['name' => 'Rentabilidad por producto', 'url' => '/reportes/rentabilidad-producto'],
            ['name' => 'Valoración de inventarios', 'url' => '/reportes/valoracion-inventarios'],
        ]],
        ['name' => 'Ensamble / Producción', 'reports' => [
            ['name' => 'Ensamble producto proceso / terminado', 'url' => '/reportes/ensambles'],
        ]],
        ['name' => 'Saldos y movimientos', 'reports' => [
            ['name' => 'Saldos de productos', 'url' => '/reportes/saldos-productos'],
            ['name' => 'Saldos de inventario', 'url' => '/reportes/saldos-inventario'],
            ['name' => 'Movimiento de productos / Kárdex', 'url' => '/reportes/kardex'],
            ['name' => 'Movimiento de productos', 'url' => '/reportes/movimiento-productos'],
            ['name' => 'Listado de productos / servicios', 'url' => '/reportes/listado-productos'],
        ]],
        ['name' => 'Remisiones', 'reports' => [
            ['name' => 'Informe de remisiones cruzadas', 'url' => '/reportes/remisiones-cruzadas'],
        ]],
    ]],
    ['section' => 'Nómina', 'groups' => [
        ['name' => 'Reportes de nómina', 'reports' => [
            ['name' => 'Prima de Servicios', 'url' => '/reportes/prima-servicios'],
            ['name' => 'Provisiones', 'url' => '/reportes/provisiones'],
            ['name' => 'Acumulados', 'url' => '/reportes/acumulados'],
            ['name' => 'Seguridad Social', 'url' => '/reportes/seguridad-social'],
        ]],
    ]],
    ['section' => 'Contables', 'groups' => [
        ['name' => 'Contables', 'reports' => [
            ['name' => 'Consecutivo de comprobantes', 'url' => '/reportes/consecutivo-comprobantes'],
            ['name' => 'Comprobantes no contabilizados', 'url' => '/reportes/comprobantes-no-contabilizados'],
            ['name' => 'Informes personalizados de movimiento contable', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento auxiliar por cuenta contable', 'url' => '/contabilidad/libro-auxiliar'],
        ]],
        ['name' => 'Financieros', 'reports' => [
            ['name' => 'Estado de situación financiera', 'url' => '/contabilidad/situacion-financiera'],
            ['name' => 'Estado de situación financiera (configurable) - Nuevo', 'url' => '/contabilidad/situacion-financiera'],
            ['name' => 'Estado de resultado integral', 'url' => '/contabilidad/estado-resultados'],
            ['name' => 'Estado de resultado Integral (configurable) - Nuevo', 'url' => '/contabilidad/estado-resultados'],
            ['name' => 'Estado de resultado integral por función de gastos', 'url' => '/contabilidad/estado-resultados'],
            ['name' => 'Estado de flujos de efectivo - Método indirecto', 'url' => '/reportes/flujo-efectivo'],
            ['name' => 'Estado de cambios en el patrimonio', 'url' => '/reportes/cambios-patrimonio'],
        ]],
        ['name' => 'Tributario / Libros oficiales', 'reports' => [
            ['name' => 'Libro de inventarios y balance', 'url' => '/contabilidad/libro-mayor'],
            ['name' => 'Libro oficial de compras', 'url' => '/reportes/libro-oficial-compras'],
            ['name' => 'Libro oficial de ventas', 'url' => '/reportes/libro-oficial-ventas'],
            ['name' => 'Libro Mayor y Balance', 'url' => '/contabilidad/libro-mayor'],
            ['name' => 'Libro diario', 'url' => '/contabilidad/libro-diario'],
            ['name' => 'Impuestos detallados', 'url' => '/reportes/impuestos-detallados'],
            ['name' => 'Comprobante informe diario', 'url' => '/contabilidad/libro-diario'],
        ]],
        ['name' => 'Balances', 'reports' => [
            ['name' => 'Balance de prueba general', 'url' => '/contabilidad/balance-prueba'],
        ]],
        ['name' => 'Activos fijos', 'reports' => [
            ['name' => 'Listado de activos fijos', 'url' => '/configuracion/activos-fijos'],
        ]],
    ]],
    ['section' => 'Seguimientos', 'groups' => [
        ['name' => 'Seguimiento comercial (CRM) y de cobranza', 'reports' => [
            ['name' => 'Seguimiento comercial (CRM)', 'url' => '/ventas/seguimiento-comercial'],
        ]],
    ]],
    ['section' => 'Auditoria', 'groups' => [
        ['name' => 'Auditoría de comprobantes y catálogos', 'reports' => [
            ['name' => 'Auditoría de borrado de comprobantes', 'url' => '/reportes/auditoria-borrado'],
        ]],
        ['name' => 'Historial de actividades', 'reports' => [
            ['name' => 'Historial de actividades realizadas en el aplicativo', 'url' => '/configuracion/auditoria'],
        ]],
    ]],
    ['section' => 'Reportes de Configuración', 'groups' => [
        ['name' => 'Localidad', 'reports' => [
            ['name' => 'Países, departamentos y ciudades', 'url' => '/reportes/paises-ciudades'],
        ]],
    ]],
    ['section' => 'Versiones anteriores de reportes', 'groups' => [
        ['name' => 'Nómina', 'reports' => [
            ['name' => 'Acumulados generales de nómina', 'url' => '/reportes/acumulados-nomina-generales'],
            ['name' => 'Acumulados detallados de nómina', 'url' => '/reportes/acumulados-nomina-detallados'],
            ['name' => 'Acumulados iniciales de nómina', 'url' => '/reportes/acumulados-iniciales-nomina'],
        ]],
        ['name' => 'Clientes y Proveedores', 'reports' => [
            ['name' => 'Cuentas por cobrar general por cliente', 'url' => '/reportes/cxc-general'],
            ['name' => 'Cuentas por cobrar detallada por documento', 'url' => '/reportes/cartera-detallada?modulo=ventas'],
            ['name' => 'Movimiento de cuentas por cobrar por documento', 'url' => '/reportes/cartera-movimiento?modulo=ventas'],
            ['name' => 'Movimiento de recibos de caja', 'url' => '/reportes/movimiento-recibos?clase=RC'],
            ['name' => 'Cuentas por pagar general por proveedor', 'url' => '/reportes/cxc-general?modulo=compras'],
            ['name' => 'Cuentas por pagar detallada por documento', 'url' => '/reportes/cartera-detallada?modulo=compras'],
            ['name' => 'Movimiento de cuentas por pagar por documento', 'url' => '/reportes/cartera-movimiento?modulo=compras'],
            ['name' => 'Movimiento de recibos de pago / egreso', 'url' => '/reportes/movimiento-recibos?clase=RP'],
        ]],
        ['name' => 'Contables', 'reports' => [
            ['name' => 'Listado de cuentas contables', 'url' => '/contabilidad/cuentas'],
            ['name' => 'Listado de comprobantes contables', 'url' => '/contabilidad/comprobantes'],
            ['name' => 'Comprobantes detallados', 'url' => '/reportes/comprobantes-detallados'],
            ['name' => 'Movimiento auxiliar por cuenta contable', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento auxiliar por cuenta contable en excel', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento auxiliar de tercero por cuenta contable', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento auxiliar de tercero por cuenta contable en Excel', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento auxiliar de cuenta contable por tercero', 'url' => '/contabilidad/libro-auxiliar'],
            ['name' => 'Movimiento Auxiliar Centro de costo por cuenta contable', 'url' => '/reportes/auxiliar-centro-costo'],
            ['name' => 'Seguimiento envío masivo de correos electrónicos', 'url' => '/reportes/seguimiento-correos'],
            ['name' => 'Libro diario', 'url' => '/contabilidad/libro-diario'],
            ['name' => 'Libro diario resumido', 'url' => '/contabilidad/libro-diario'],
            ['name' => 'Balance de prueba general', 'url' => '/contabilidad/balance-prueba'],
            ['name' => 'Balance de prueba general en excel', 'url' => '/contabilidad/balance-prueba'],
            ['name' => 'Balance de prueba por tercero', 'url' => '/contabilidad/balance-prueba?por_tercero=1'],
            ['name' => 'Balance de prueba por tercero en excel', 'url' => '/contabilidad/balance-prueba?por_tercero=1'],
            ['name' => 'Balance de prueba por centro de costo', 'url' => '/reportes/balance-centro-costo'],
            ['name' => 'Balance de prueba por centro de costo en excel', 'url' => '/reportes/balance-centro-costo'],
        ]],
    ]],
];
