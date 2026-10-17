<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Eloquent\Model;

/**
 * En el respaldo los valores cifrados (correos, telefonos, claves, certificados, historial) viajan descifrados
 * dentro del ZIP protegido con la contraseña del respaldo, marcados como ⟦enc:base64⟧. Al restaurar se vuelven a
 * cifrar con la llave del sistema destino, asi el respaldo sirve entre Nubicta privado y Nubicta nube.
 */
final class SecretCodec
{
    private const PAYLOAD = '/eyJpdiI6(?:[A-Za-z0-9+=]|\\\\?\/)+/';

    private const MARK = '/⟦enc:([A-Za-z0-9+\/=]*)⟧/u';

    public function __construct(private StringEncrypter $encrypter) {}

    /** El mismo cifrador que usan los casts 'encrypted' de los modelos. */
    public static function current(): self
    {
        $model = new class extends Model
        {
            public static function encrypterInUse(): StringEncrypter
            {
                return static::currentEncrypter();
            }
        };

        return new self($model::encrypterInUse());
    }

    public function open(string $value): string
    {
        if (! str_contains($value, 'eyJpdiI6')) {
            return $value;
        }

        return preg_replace_callback(self::PAYLOAD, function ($m) {
            $payload = str_replace('\\/', '/', $m[0]);
            if (! self::isPayload($payload)) {
                return $m[0];
            }
            try {
                return '⟦enc:'.base64_encode($this->encrypter->decryptString($payload)).'⟧';
            } catch (\Throwable) {
                return $m[0];
            }
        }, $value) ?? $value;
    }

    public function seal(string $value): string
    {
        if (! str_contains($value, '⟦enc:')) {
            return $value;
        }

        return preg_replace_callback(self::MARK, fn ($m) => $this->encrypter->encryptString((string) base64_decode($m[1])), $value) ?? $value;
    }

    /** Quita las marcas y deja el texto plano (para columnas que este sistema no cifra). */
    public function reveal(string $value): string
    {
        if (! str_contains($value, '⟦enc:')) {
            return $value;
        }

        $json = in_array(ltrim($value)[0] ?? '', ['{', '['], true);

        return preg_replace_callback(self::MARK, function ($m) use ($json) {
            $plain = (string) base64_decode($m[1]);

            return $json ? substr(json_encode($plain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1) : $plain;
        }, $value) ?? $value;
    }

    /** Valor para una columna que este sistema guarda cifrada completa: si llego en texto plano, se cifra. */
    public function sealAll(string $value): string
    {
        if (str_contains($value, '⟦enc:')) {
            return $this->seal($value);
        }
        if ($value === '' || self::isPayload($value)) {
            return $value;
        }

        return $this->encrypter->encryptString($value);
    }

    public static function isPayload(string $value): bool
    {
        $json = base64_decode($value, true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) && isset($data['iv'], $data['value']);
    }
}
