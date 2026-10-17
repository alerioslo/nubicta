<?php

namespace App\Services\Mail;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Servidor SMTP de cada empresa (Configuracion > Desbloqueo de correos), guardado en app_settings.
 * Si la empresa no lo ha configurado se usa el correo del .env.
 */
class CompanyMailer
{
    public static function settings(?int $companyId): array
    {
        $saved = json_decode((string) DB::table('app_settings')->where('company_id', $companyId)->where('key', 'correos')->value('value'), true) ?: [];
        foreach (['password', 'imap_password'] as $key) {
            if (filled($saved[$key] ?? null)) {
                $saved[$key] = self::reveal($saved[$key]);
            }
        }

        return $saved;
    }

    public static function hasOwnServer(?int $companyId): bool
    {
        $s = self::settings($companyId);

        return filled($s['host'] ?? null) && filled($s['username'] ?? null) && filled($s['password'] ?? null);
    }

    public static function configured(?int $companyId): bool
    {
        return self::hasOwnServer($companyId) || ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    public static function mailer(?int $companyId): Mailer
    {
        if (! self::hasOwnServer($companyId)) {
            return Mail::mailer();
        }
        $s = self::settings($companyId);
        $name = "company_{$companyId}";
        $encryption = $s['encryption'] ?? 'tls';
        config(["mail.mailers.{$name}" => [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $s['host'],
            'port' => (int) ($s['port'] ?? 587),
            'username' => $s['username'],
            'password' => $s['password'],
            'timeout' => 20,
            'auto_tls' => $encryption !== 'ninguno',
        ]]);
        Mail::purge($name);

        return Mail::mailer($name);
    }

    /** @return array{0:string,1:?string} correo y nombre del remitente */
    public static function from(?int $companyId, ?string $fallbackName = null): array
    {
        $s = self::settings($companyId);
        $email = filled($s['from_email'] ?? null) ? $s['from_email'] : (self::hasOwnServer($companyId) ? $s['username'] : config('mail.from.address'));

        return [$email, filled($s['from_name'] ?? null) ? $s['from_name'] : ($fallbackName ?: config('mail.from.name'))];
    }

    public static function blocked(?int $companyId, string $email): bool
    {
        $list = preg_split('/\R/', (string) (self::settings($companyId)['blocked'] ?? '')) ?: [];

        return in_array(mb_strtolower(trim($email)), array_map(fn ($l) => mb_strtolower(trim($l)), $list), true);
    }

    public static function protect(string $password): string
    {
        return 'enc:'.Crypt::encryptString($password);
    }

    public static function reveal(string $value): string
    {
        return str_starts_with($value, 'enc:') ? Crypt::decryptString(substr($value, 4)) : $value;
    }
}
