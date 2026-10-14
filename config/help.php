<?php

/* Centro de ayuda: guias de cada modulo del ERP (id = ancla usada por los botones "Ver tutoriales"). */
return [
    ['id' => 'inicio', 'title' => 'Primeros pasos', 'icon' => 'house', 'guides' => [
        ['Buscar cualquier cosa', 'Usa la lupa de la barra superior para encontrar terceros (nombre o NIT), productos (código, nombre o código de barras), documentos (por ejemplo FV-2-71000 o CC-1-300), cuentas contables (por código) y pantallas.', null],
        ['Crear documentos', 'El botón "Crear" agrupa todos los documentos: facturas, notas, recibos, comprobantes contables, documentos de inventario y nómina.', null],
        ['Notificaciones', 'La campana muestra alertas calculadas al momento: cartera vencida, pagos próximos, inventario bajo el mínimo o negativo, nóminas sin contabilizar o sin pagar, movimientos bancarios sin conciliar y tareas comerciales vencidas.', null],
        ['Usuarios y permisos', 'En Configuración › Usuarios creas usuarios y en Perfiles defines qué puede ver, crear, editar, anular o exportar cada uno.', '/configuracion/usuarios'],
    ]],
    ['id' => 'ventas', 'title' => 'Ventas', 'icon' => 'dollar-sign', 'guides' => [
        ['Factura de venta', 'Crear › Factura de venta. Elige cliente, productos y forma de pago (contado o crédito). Al guardar se contabiliza, se descuenta el inventario y queda en la cartera si es a crédito.', '/ventas/facturas/crear'],
        ['Ver, imprimir, anular o editar', 'Haz clic en el número del documento en el listado de Ventas. Desde el detalle puedes imprimir, descargar el XML, editar o anular. Si el documento tiene pagos, notas o ya fue aceptado por la DIAN, el sistema te indica qué hacer primero.', '/ventas'],
        ['Recibos de caja', 'Crear › Recibo de caja: selecciona el cliente y marca las facturas que paga; el saldo de cartera se actualiza.', '/ventas/recibos/crear'],
        ['Cotizaciones y remisiones', 'Se crean desde sus pestañas en Ventas. Una remisión mueve inventario sin contabilizar ingreso.', '/ventas/cotizaciones'],
        ['Facturas recurrentes', 'Toma una factura existente como plantilla, define la frecuencia y el ERP genera la factura automáticamente cada periodo.', '/ventas/facturas-recurrentes'],
        ['Impresión y envío masivo', 'Filtra por fechas o cliente, selecciona los comprobantes e imprímelos juntos o envíalos por correo al cliente con el PDF adjunto.', '/ventas/envio-masivo'],
        ['Seguimiento comercial', 'Registra tareas con cliente, responsable, etapa y fecha de compromiso; las vencidas aparecen en la campana.', '/ventas/seguimiento-comercial'],
    ]],
    ['id' => 'compras', 'title' => 'Compras y gastos', 'icon' => 'download', 'guides' => [
        ['Factura de compra', 'Crear › Factura de compra. Puedes importar el XML de la factura electrónica del proveedor para no digitarla.', '/compras/facturas/crear'],
        ['Pagos a proveedores', 'Crear › Recibo de pago / egreso: selecciona el proveedor y las facturas que pagas.', '/compras/egresos/crear'],
        ['Documento soporte', 'Para compras a personas no obligadas a facturar.', '/compras/documentos-soporte/crear'],
    ]],
    ['id' => 'productos', 'title' => 'Productos e inventario', 'icon' => 'bag-shopping', 'guides' => [
        ['Kardex', 'En el listado de productos abre el kardex para ver entradas, salidas, saldo y costo promedio.', '/productos'],
        ['Traslados, ajustes, conteos y ensambles', 'Desde Productos › Documentos de productos. Cada documento mueve el inventario y genera su comprobante contable.', '/productos/documentos'],
        ['Combos', 'Un combo se vende como un solo producto y al facturarlo descuenta del inventario cada componente.', '/productos/combos'],
        ['Costeo mensual', 'Recalcula en orden cronológico el costo promedio del mes y ajusta el costo de ventas contabilizado cuando hubo documentos con fecha anterior.', '/productos/costeo'],
    ]],
    ['id' => 'bancos', 'title' => 'Cajas y bancos', 'icon' => 'landmark', 'guides' => [
        ['Traslado de dinero', 'Crear › Traslado de dinero entre caja y bancos.', '/bancos/traslados/crear'],
        ['Conciliación bancaria', 'Descarga el extracto de la Sucursal Virtual Bancolombia (Excel, CSV o TXT) e impórtalo. El ERP cruza automáticamente por valor y fecha; lo que quede lo cruzas a mano o lo contabilizas (4x1000, cuota de manejo, comisiones, intereses).', '/bancos/conciliacion'],
    ]],
    ['id' => 'nomina', 'title' => 'Nómina', 'icon' => 'user-tie', 'guides' => [
        ['Crear la nómina', 'Nómina › Crear nómina: elige el periodo; se liquidan sueldo, auxilio de transporte, salud, pensión, aportes y provisiones de cada empleado activo.', '/nomina/crear'],
        ['Novedades', 'En la nómina en borrador abre el empleado y agrega horas extras, recargos, incapacidades, vacaciones, licencias, préstamos o libranzas; se recalcula al instante.', null],
        ['Contabilizar y pagar', 'Contabilizar genera un comprobante por empleado (CC-992). Luego registra el pago desde caja o banco (CC-8). Si hay un error, "Reabrir" reversa todo y vuelve a borrador.', null],
        ['Prima, cesantías y liquidación', 'Desde Nómina › Más procesos. Cada proceso usa primero lo provisionado y lleva la diferencia al gasto.', '/nomina/mas-procesos'],
        ['PILA, vacaciones y certificado 220', 'En Más procesos: resumen de aportes del mes con descarga CSV, libro de vacaciones y certificado de ingresos y retenciones por empleado.', '/nomina/mas-procesos'],
    ]],
    ['id' => 'contabilidad', 'title' => 'Contabilidad', 'icon' => 'book', 'guides' => [
        ['Comprobante contable', 'Crear › Comprobante contable. Los comprobantes se pueden marcar como favoritos y copiar para no digitarlos de nuevo.', '/contabilidad/comprobantes/crear'],
        ['Libros y estados financieros', 'Libro auxiliar, mayor, diario, balance de prueba, situación financiera y estado de resultados en Contabilidad.', '/contabilidad/libro-auxiliar'],
        ['Presupuesto', 'Define valores por cuenta y mes y compáralos con lo ejecutado según la contabilidad.', '/contabilidad/presupuesto'],
        ['Cierre de año', 'Cancela las cuentas de resultado (4, 5, 6 y 7) contra la utilidad o pérdida del ejercicio.', '/contabilidad/cierre-anio'],
        ['Medios magnéticos y certificados', 'Asistente de información exógena (formatos 1001, 1003, 1005, 1006, 1007, 1008 y 1009) y certificados de retención en la fuente, IVA e ICA por tercero.', '/contabilidad/medios-magneticos'],
    ]],
    ['id' => 'reportes', 'title' => 'Reportes', 'icon' => 'chart-column', 'guides' => [
        ['Catálogo de reportes', 'Todos los reportes se filtran por fechas y se descargan en Excel.', '/reportes'],
    ]],
];
