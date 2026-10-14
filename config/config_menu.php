<?php

/* Generado por tools/gen_config_menu.py desde docs/siigo/crawl/config.json */

return [
    'General' => [
        [
            'title' => 'Organización',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Perfil de la organización',
                    'url' => '/configuracion/empresa',
                ],
                [
                    'label' => 'Logo para documentos',
                    'url' => '/configuracion/empresa',
                ],
                [
                    'label' => 'Contrato Siigo Nube',
                    'url' => '/configuracion/ajustes/contrato',
                ],
            ],
        ],
        [
            'title' => 'Usuarios',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Invita a tu contador',
                    'url' => '/configuracion/usuarios/crear',
                ],
                [
                    'label' => 'Administración de usuarios y permisos',
                    'url' => '/configuracion/usuarios',
                ],
            ],
        ],
        [
            'title' => 'Seguridad',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Bloqueo de transacciones por periodo',
                    'url' => '/configuracion/bloqueo-periodos',
                ],
            ],
        ],
        [
            'title' => 'Regional',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Ciudades',
                    'url' => '/configuracion/ciudades',
                ],
            ],
        ],
    ],
    'Habilitaciones DIAN' => [
        [
            'title' => 'Ventas',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Facturación Electrónica',
                    'url' => '/configuracion/facturacion-electronica',
                ],
                [
                    'label' => 'Sincroniza tu resolución',
                    'url' => '/configuracion/facturacion-electronica?tab=resoluciones',
                ],
            ],
        ],
        [
            'title' => 'Nómina',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Nómina Electrónica',
                    'url' => '/configuracion/facturacion-electronica',
                ],
            ],
        ],
        [
            'title' => 'Compras y gastos',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Documento soporte',
                    'url' => '/configuracion/facturacion-electronica',
                ],
                [
                    'label' => 'Sincroniza tu resolución de documento soporte',
                    'url' => '/configuracion/facturacion-electronica?tab=resoluciones',
                ],
            ],
        ],
        [
            'title' => 'Certificado digital',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Seguimiento Certificado digital',
                    'url' => '/configuracion/facturacion-electronica?tab=certificado',
                ],
            ],
        ],
    ],
    'Ventas' => [
        [
            'title' => 'Documentos',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Facturas de ventas / Ingresos',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Cotizaciones',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Remisiones',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Notas débito (Ventas)',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Recibos de caja',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Notas Crédito',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
        [
            'title' => 'Procesos',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Renovación de numeración agotada o próxima a agotarse',
                    'url' => '/configuracion/facturacion-electronica?tab=resoluciones',
                ],
                [
                    'label' => 'Habilitación de numeración vencida o próxima a vencerse',
                    'url' => '/configuracion/facturacion-electronica?tab=resoluciones',
                ],
            ],
        ],
        [
            'title' => 'Seguimiento',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Tareas de seguimiento de cobranza',
                    'url' => '/configuracion/ajustes/cobranza',
                ],
                [
                    'label' => 'Tarea de seguimiento comercial',
                    'url' => '/ventas/seguimiento-comercial',
                ],
            ],
        ],
        [
            'title' => 'Importación',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Facturas de ventas / Ingresos',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
        [
            'title' => 'Impresión, estilo y envío de documentos',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Facturas de ventas / Ingresos',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Cotizaciones',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Remisiones',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Notas débito (Ventas)',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Recibos de caja',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Notas Crédito',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
    ],
    'Compras y gastos' => [
        [
            'title' => 'Documentos',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Compras y gastos',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Órdenes de compra',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Documento soporte',
                    'url' => '/configuracion/facturacion-electronica',
                ],
                [
                    'label' => 'Nota débito / Nota de ajuste documento soporte',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Recibo de Pago / Egreso',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
        [
            'title' => 'Impresión, estilo y envío de documentos',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Compras y gastos',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Órdenes de compra',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Documento soporte',
                    'url' => '/configuracion/facturacion-electronica',
                ],
                [
                    'label' => 'Nota débito / Nota de ajuste documento soporte',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Recibo de Pago / Egreso',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
    ],
    'Clientes y proveedores' => [
        [
            'title' => 'Generales',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Campos libres de clientes, proveedores u otros',
                    'url' => '/configuracion/campos-libres',
                ],
                [
                    'label' => 'Desbloqueo de correos para envío de emails y notificaciones',
                    'url' => '/configuracion/ajustes/correos',
                ],
            ],
        ],
        [
            'title' => 'Importación',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Saldos iniciales cuentas por cobrar',
                    'url' => '/configuracion/saldos-iniciales/cuentas-por-cobrar',
                ],
                [
                    'label' => 'Saldos iniciales cuentas por pagar',
                    'url' => '/configuracion/saldos-iniciales/cuentas-por-pagar',
                ],
                [
                    'label' => 'Clientes, proveedores u otros',
                    'url' => '/terceros',
                ],
            ],
        ],
        [
            'title' => 'Gestión de recaudo',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Configuración de recordatorios automáticos',
                    'url' => '/configuracion/ajustes/recordatorios',
                ],
            ],
        ],
    ],
    'Productos y servicios' => [
        [
            'title' => 'Generales',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Categorías de productos y servicios',
                    'url' => '/configuracion/lineas-producto',
                ],
                [
                    'label' => 'Listas de precios de venta',
                    'url' => '/configuracion/listas-precios',
                ],
                [
                    'label' => 'Configuración de bodegas',
                    'url' => '/configuracion/bodegas',
                ],
                [
                    'label' => 'Unidades de medida factura electrónica',
                    'url' => '/configuracion/unidades-medida',
                ],
                [
                    'label' => 'Configuración de inventarios',
                    'url' => '/productos/costeo',
                ],
            ],
        ],
        [
            'title' => 'Documentos',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Notas de ensamble',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
        [
            'title' => 'Importación',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Productos y servicios',
                    'url' => '/productos',
                ],
                [
                    'label' => 'Ajuste de inventario',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Saldos iniciales de inventario',
                    'url' => '/configuracion/saldos-iniciales/inventario',
                ],
                [
                    'label' => 'Conteo físico',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
    ],
    'Nómina' => [
        [
            'title' => 'Generales',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Creación de cargos laborales',
                    'url' => '/configuracion/cargos',
                ],
                [
                    'label' => 'Configuración de nómina',
                    'url' => '/configuracion/nomina',
                ],
                [
                    'label' => 'Conceptos de nómina',
                    'url' => '/configuracion/conceptos-nomina',
                ],
                [
                    'label' => 'Parámetros contables de nómina',
                    'url' => '/configuracion/parametros-nomina',
                ],
                [
                    'label' => 'Configuración de prefijos',
                    'url' => '/configuracion/facturacion-electronica?tab=resoluciones',
                ],
            ],
        ],
        [
            'title' => 'Importación',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Empleados',
                    'url' => '/nomina/empleados',
                ],
                [
                    'label' => 'Contratos',
                    'url' => '/nomina/empleados',
                ],
                [
                    'label' => 'Acumulados iniciales',
                    'url' => '/reportes/acumulados-iniciales-nomina',
                ],
                [
                    'label' => 'Salarios',
                    'url' => '/nomina/empleados',
                ],
            ],
        ],
    ],
    'Contabilidad' => [
        [
            'title' => 'Generales',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Impuestos',
                    'url' => '/configuracion/impuestos',
                ],
                [
                    'label' => 'Centro de costos',
                    'url' => '/configuracion/centros-costo',
                ],
                [
                    'label' => 'Cuentas contables',
                    'url' => '/contabilidad/cuentas',
                ],
                [
                    'label' => 'Gastos / Costos frecuentes',
                    'url' => '/configuracion/gastos-frecuentes',
                ],
                [
                    'label' => 'Otros ingresos',
                    'url' => '/configuracion/otros-ingresos',
                ],
                [
                    'label' => 'Formas de pago en documentos',
                    'url' => '/configuracion/formas-pago',
                ],
            ],
        ],
        [
            'title' => 'Documentos',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Comprobantes contables',
                    'url' => '/configuracion/tipos-documento',
                ],
            ],
        ],
        [
            'title' => 'Activos fijos',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Grupos de activos',
                    'url' => '/configuracion/grupos-activos',
                ],
            ],
        ],
        [
            'title' => 'Importación',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Comprobantes contables',
                    'url' => '/configuracion/tipos-documento',
                ],
                [
                    'label' => 'Activos fijos',
                    'url' => '/configuracion/activos-fijos',
                ],
                [
                    'label' => 'Saldos iniciales de otras cuentas contables',
                    'url' => '/contabilidad/cuentas',
                ],
            ],
        ],
    ],
    'Alianzas e integraciones' => [
        [
            'title' => 'Configuraciones e integraciones',
            'column' => 0,
            'items' => [
                [
                    'label' => 'Credenciales de integración a plataformas digitales (Siigo API)',
                    'url' => '/configuracion/ajustes/api',
                ],
            ],
        ],
        [
            'title' => 'Gestión de recaudo',
            'column' => 1,
            'items' => [
                [
                    'label' => 'Configuración de recordatorios automáticos',
                    'url' => '/configuracion/ajustes/recordatorios',
                ],
            ],
        ],
    ],
];
