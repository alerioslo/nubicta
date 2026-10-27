<?php

namespace App\Services\Documents;

use App\Models\DocumentType;
use App\Support\ColombianNit;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Factura por mandato: cuentas de la empresa, mandante y la referencia con la que el cliente evita duplicar el documento. */
class Mandate
{
    /** @var array<string, string> */
    public const ACCOUNTS = [
        'bridge' => 'la cuenta puente de recaudo',
        'liability' => 'el pasivo de mandato',
        'vat' => 'el IVA de mandato',
        'adjustment' => 'la cuenta del ajuste',
    ];

    /** @return array<string, string> */
    public static function codes(): array
    {
        $saved = json_decode((string) DB::table('app_settings')->where('company_id', CurrentCompany::id())->where('key', 'mandato')->value('value'), true) ?: [];

        return collect(self::ACCOUNTS)->mapWithKeys(fn ($label, $key) => [$key => trim((string) ($saved[$key] ?? ''))])->all();
    }

    public static function id(string $key): int
    {
        $code = self::codes()[$key] ?? '';
        if ($code === '') {
            throw ValidationException::withMessages(['cuentas' => 'Falta configurar '.self::ACCOUNTS[$key].' en Configuración, Cuentas de mandato. No se envió nada a la DIAN.']);
        }
        $id = DB::table('accounts')->where('company_id', CurrentCompany::id())->where('code', $code)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['cuentas' => 'La cuenta '.self::ACCOUNTS[$key]." ({$code}) no existe en el PUC. No se envió nada a la DIAN."]);
        }

        return (int) $id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function assertReady(string $kind, DocumentType $type, array $lines, array $data): void
    {
        $mandante = collect($lines)->contains(fn ($l) => ($l['role'] ?? null) === 'mandante');
        $zz = collect($lines)->contains(fn ($l) => ($l['tax_code'] ?? null) === 'ZZ');
        $settled = ($data['payment_form'] ?? null) === 'contado';
        if ($mandante && (empty($data['principal']['identificacion']) || empty($data['principal']['nombre']))) {
            throw ValidationException::withMessages(['mandante' => 'Indica el mandante (identificación y nombre) cuando hay una línea del mandante.']);
        }
        if (! empty($data['related_id']) && ! empty($data['external_invoice'])) {
            throw ValidationException::withMessages(['factura_externa' => 'Indica la factura del sistema o una factura externa, no las dos.']);
        }

        $need = [];
        if ($mandante) {
            $need = array_keys(self::ACCOUNTS);
        } else {
            if ($settled) {
                $need[] = 'bridge';
            }
            if ($zz) {
                $need[] = 'adjustment';
            }
        }
        $codes = self::codes();
        $missing = [];
        foreach ($need as $key) {
            if (($codes[$key] ?? '') === '') {
                $missing[] = self::ACCOUNTS[$key];
            }
        }
        if ($missing) {
            throw ValidationException::withMessages(['cuentas' => 'Falta configurar '.implode(', ', $missing).' en Configuración, Cuentas de mandato. No se envió nada a la DIAN.']);
        }
        foreach ($need as $key) {
            self::id($key);
        }
    }

    /** @param  array<string, mixed>  $in */
    public static function principal(array $in): array
    {
        $type = (string) ($in['tipo_documento'] ?? '31');
        $id = preg_replace('/\D/', '', (string) ($in['identificacion'] ?? '')) ?? '';
        $dv = trim((string) ($in['dv'] ?? ''));
        if ($type === '31' && $id !== '' && $dv === '') {
            $dv = (string) ColombianNit::resolveVerificationDigit($id, null);
        }
        $code = preg_replace('/\D/', '', (string) ($in['codigo_ciudad'] ?? '')) ?? '';
        $city = trim((string) ($in['ciudad'] ?? ''));
        if ($city === '' && $code !== '') {
            $city = (string) DB::table('municipalities')->where('code', $code)->value('name');
        }

        return [
            'identificacion' => $id,
            'dv' => $dv !== '' ? $dv : null,
            'tipo_documento' => $type !== '' ? $type : '31',
            'nombre' => trim((string) ($in['nombre'] ?? '')),
            'ciudad' => $city !== '' ? $city : null,
            'codigo_ciudad' => $code !== '' ? $code : null,
            'regimen' => self::text($in['regimen'] ?? null),
            'correo' => self::text($in['correo'] ?? null),
            'direccion' => self::text($in['direccion'] ?? null),
            'logo_url' => self::text($in['logo_url'] ?? null),
            'telefono' => self::text($in['telefono'] ?? null),
            'whatsapp' => self::text($in['whatsapp'] ?? null),
            'sitio' => self::text($in['sitio'] ?? null),
            'atencion' => self::text($in['atencion'] ?? null),
            'cuentas' => self::accounts($in['cuentas'] ?? []),
        ];
    }

    /** @param  array<string, mixed>  $in */
    public static function externalInvoice(array $in): array
    {
        foreach (['numero' => 'el número', 'cufe' => 'el CUFE', 'fecha' => 'la fecha'] as $key => $label) {
            if (! filled($in[$key] ?? null)) {
                throw ValidationException::withMessages(['factura_externa' => "La factura externa necesita {$label}."]);
            }
        }

        return [
            'numero' => trim((string) $in['numero']),
            'cufe' => trim((string) $in['cufe']),
            'fecha' => substr((string) $in['fecha'], 0, 10),
        ];
    }

    public static function requestHash(array $body): string
    {
        return hash('sha256', json_encode(self::sort($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    /** @return list<array{banco:string, tipo:?string, numero:?string}> */
    private static function accounts(mixed $rows): array
    {
        if (is_string($rows)) {
            $rows = array_filter(array_map('trim', preg_split('/\r\n|\n/', $rows) ?: []));
            $rows = array_map(function (string $line) {
                $parts = array_map('trim', explode(';', $line));

                return ['banco' => $parts[0] ?? '', 'tipo' => $parts[1] ?? null, 'numero' => $parts[2] ?? null];
            }, $rows);
        }
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row) || ! filled($row['banco'] ?? null)) {
                return null;
            }

            return [
                'banco' => trim((string) $row['banco']),
                'tipo' => self::text($row['tipo'] ?? null),
                'numero' => self::text($row['numero'] ?? null),
            ];
        }, $rows)));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sort'], $value);
        }
        ksort($value);

        return array_map([self::class, 'sort'], $value);
    }
}
