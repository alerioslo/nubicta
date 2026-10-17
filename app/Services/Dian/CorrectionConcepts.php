<?php

namespace App\Services\Dian;

/** Conceptos de correccion de las notas credito y debito (Anexo tecnico de factura electronica, tabla 13.2.4). */
final class CorrectionConcepts
{
    public const NC = [
        '1' => 'Devolución de parte de los bienes; no aceptación de partes del servicio',
        '2' => 'Anulación de factura electrónica',
        '3' => 'Rebaja o descuento parcial o total',
        '4' => 'Ajuste de precio',
        '5' => 'Descuento comercial por pronto pago',
        '6' => 'Descuento comercial por volumen de ventas',
    ];

    public const ND = [
        '1' => 'Intereses',
        '2' => 'Gastos por cobrar',
        '3' => 'Cambio del valor',
        '4' => 'Otros',
    ];

    /** @return array{code: string, description: string} */
    public static function for(string $kind, ?string $code, ?string $fallback = null): array
    {
        $list = $kind === 'ND' ? self::ND : self::NC;
        $code = isset($list[(string) $code]) ? (string) $code : ($kind === 'ND' ? '3' : '1');

        return ['code' => $code, 'description' => $fallback ?: $list[$code]];
    }
}
