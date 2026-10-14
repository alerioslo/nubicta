<?php

namespace App\Support;

use RuntimeException;

final class DianUnitCatalog
{
    /** @var array<string, string> */
    private const COMMON = [
        '94' => 'Unidad',
        'EA' => 'Cada uno',
        'HUR' => 'Hora',
        'DAY' => 'Día',
        'LUN' => 'Mes',
        'ANN' => 'Año',
        'ANA' => 'Año',
        'DZN' => 'Docena',
        'KGM' => 'Kilogramo',
        'GRM' => 'Gramo',
        'LBR' => 'Libra',
        'TNE' => 'Tonelada métrica',
        'LTR' => 'Litro',
        'GLL' => 'Galón',
        'MTR' => 'Metro',
        'CMT' => 'Centímetro',
        'MMT' => 'Milímetro',
        'KMT' => 'Kilómetro',
        'MTK' => 'Metro cuadrado',
        'MTQ' => 'Metro cúbico',
        'BX' => 'Caja',
        'PK' => 'Paquete',
        'PR' => 'Par',
        'SET' => 'Juego',
        'ZZ' => 'Mutuamente definido',
    ];

    /** @var array<string, string>|null */
    private ?array $options = null;

    /** @return array<string, string> */
    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $options = [];
        foreach (self::COMMON as $code => $name) {
            $options[$code] = "{$code} — {$name}";
        }

        $path = database_path('data/unece_rec20_units.csv');
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No fue posible cargar el catálogo de unidades DIAN.');
        }

        try {
            fgetcsv($handle, null, ',', '"', '');
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $code = trim((string) ($row[1] ?? ''));
                $name = trim((string) ($row[2] ?? ''));
                $symbol = trim((string) ($row[5] ?? ''));
                if ($code === '' || $name === '' || isset($options[$code])) {
                    continue;
                }

                $options[$code] = $code.' — '.$name.($symbol !== '' ? " ({$symbol})" : '');
            }
        } finally {
            fclose($handle);
        }

        return $this->options = $options;
    }
}
