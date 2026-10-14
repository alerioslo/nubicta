<?php

// Cuentas por defecto (codigos PUC de la empresa) cuando el parametro no trae la suya.
return [
    'defaults' => [
        'customers' => '13050501',
        'suppliers' => '22050501',
        'cash' => '11050501',
        'customer_advances' => '28050501',
        'supplier_advances' => '13300501',
        'income' => '41350101',
        'inventory' => '14350101',
        'cost' => '61350501',
        'sales_returns' => '41750501',
        'sales_discounts' => '53053501',
        'other_income' => '42950501',
        'other_expenses' => '53959501',
    ],

    // Contrapartida de los conceptos del ajuste de cartera / proveedor.
    'portfolio_concepts' => [
        'ventas' => [
            'Descuento' => '53053501', 'Retención' => '13551501', 'Ajuste al peso' => '53959501',
            'Diferencia en cambio' => '53959501', 'Castigo de cartera' => '53959501', 'Otro' => '53959501',
        ],
        'compras' => [
            'Descuento' => '42104001', 'Retención' => '23654001', 'Ajuste al peso' => '42950501',
            'Diferencia en cambio' => '42102001', 'Castigo de cartera' => '42950501', 'Otro' => '42950501',
        ],
    ],

    // Partidas del extracto que se contabilizan desde la conciliacion: palabras clave de la descripcion Bancolombia.
    'bank_concepts' => [
        'gmf' => ['label' => 'Gravamen 4x1000', 'account' => '51159501', 'keywords' => ['4X1000', 'GMF', 'GRAVAMEN', 'IMPTO GOBIERNO', 'IMPUESTO GOBIERNO']],
        'fees' => ['label' => 'Gastos bancarios', 'account' => '53050501', 'keywords' => ['CUOTA MANEJO', 'CUOTA DE MANEJO', 'COBRO', 'CARGO', 'IVA ', 'TARJETA', 'CHEQUERA', 'SERVICIO']],
        'commission' => ['label' => 'Comisiones', 'account' => '53051501', 'keywords' => ['COMISION', 'COMISIÓN']],
        'interest_expense' => ['label' => 'Intereses pagados', 'account' => '53052001', 'keywords' => ['INTERES SOBREGIRO', 'INTERESES SOBREGIRO', 'INTERES CREDITO']],
        'interest_income' => ['label' => 'Intereses recibidos', 'account' => '42950501', 'keywords' => ['ABONO INTERESES', 'INTERESES AHORRO', 'RENDIMIENTO']],
        'withholding' => ['label' => 'Retención en la fuente', 'account' => '13551501', 'keywords' => ['RETEFUENTE', 'RETENCION']],
        'other' => ['label' => 'Otra cuenta', 'account' => null, 'keywords' => []],
    ],
    'bank_contact' => '890903938',
];
