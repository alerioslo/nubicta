<?php

namespace App\Services\Payroll;

use App\Models\Company;
use App\Models\PayrollDocument;
use App\Services\Mail\CompanyMailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Nomina > Documentos electronicos > Envios por correo: envia al empleado el PDF (y el XML si existe) de su documento soporte de pago. */
class PayrollMailer
{
    public function send(PayrollDocument $doc, ?string $to = null): void
    {
        if (! CompanyMailer::configured($doc->company_id)) {
            throw new RuntimeException('El servidor no tiene configurado el correo de salida (SMTP).');
        }
        $to = trim((string) ($to ?: self::emailFor($doc)));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("El empleado de {$doc->consecutive} no tiene un correo electrónico válido.");
        }
        if (CompanyMailer::blocked($doc->company_id, $to)) {
            throw new RuntimeException("El correo {$to} está bloqueado. Desbloquéalo en Configuración > Desbloqueo de correos.");
        }
        $company = Company::query()->find($doc->company_id);
        $from = $company?->business_name ?: trim("{$company?->first_name} {$company?->last_name}");
        [$fromEmail, $fromName] = CompanyMailer::from($doc->company_id, $from);
        $pdf = $this->pdf($doc);
        $xml = $doc->xml_path && Storage::disk('local')->exists($doc->xml_path) ? Storage::disk('local')->path($doc->xml_path) : null;
        $name = str_replace('-', '', $doc->consecutive);

        try {
            CompanyMailer::mailer($doc->company_id)->send([], [], function (Message $m) use ($doc, $to, $company, $from, $fromEmail, $fromName, $pdf, $xml, $name) {
                $m->from($fromEmail, $fromName)->to($to)->subject("{$doc->type} de nómina electrónica {$doc->consecutive} - {$from}");
                if ($company?->email) {
                    $m->replyTo($company->email, $from);
                }
                $m->html(view('mail.payroll', ['doc' => $doc, 'company' => $from])->render());
                $m->attach($pdf, ['as' => "$name.pdf", 'mime' => 'application/pdf']);
                if ($xml) {
                    $m->attach($xml, ['as' => "$name.xml", 'mime' => 'application/xml']);
                }
            });
        } catch (\Throwable $e) {
            $doc->forceFill(['mail_status' => 'Envío fallido'])->save();
            throw new RuntimeException("No se pudo enviar {$doc->consecutive}: ".mb_substr($e->getMessage(), 0, 200), 0, $e);
        } finally {
            @unlink($pdf);
        }
        $doc->forceFill(['mail_status' => 'Enviado', 'mail_sent_at' => now(), 'email' => $to])->save();
    }

    public static function emailFor(PayrollDocument $doc): ?string
    {
        return $doc->email ?: $doc->contact?->email;
    }

    /** PDF generado con Chromium sin interfaz desde la vista de impresion firmada del documento. */
    public function pdf(PayrollDocument $doc): string
    {
        $url = URL::temporarySignedRoute('payroll.signed-print', now()->addMinutes(5), ['id' => $doc->id, 'c' => $doc->company_id]);
        $file = sys_get_temp_dir().'/nomina_'.$doc->id.'_'.bin2hex(random_bytes(4)).'.pdf';
        $process = new Process([
            config('services.chromium', '/usr/bin/chromium'), '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--no-pdf-header-footer', '--virtual-time-budget=15000', '--run-all-compositor-stages-before-draw', "--print-to-pdf={$file}", $url,
        ], null, ['HOME' => sys_get_temp_dir()], null, 90);
        $process->run();
        if (! is_file($file) || filesize($file) < 1000) {
            @unlink($file);
            throw new RuntimeException('No se pudo generar el PDF: '.mb_substr(trim($process->getErrorOutput()), 0, 200));
        }

        return $file;
    }
}
