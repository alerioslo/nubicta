<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Services\Dian\DianService;
use App\Services\Mail\CompanyMailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Envia comprobantes de venta y recibos al correo del cliente con la representacion grafica en PDF (y el XML si existe). */
class DocumentMailer
{
    public static function configured(?int $companyId = null): bool
    {
        return CompanyMailer::configured($companyId ?? \App\Support\CurrentCompany::id());
    }

    public function send(SalesDocument|Receipt $doc, ?string $to = null): void
    {
        if (! self::configured($doc->company_id)) {
            throw new RuntimeException('El servidor no tiene configurado el correo de salida (SMTP).');
        }
        $to = trim((string) ($to ?: $doc->contact?->email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("El cliente de {$doc->name} no tiene un correo electrónico válido.");
        }
        if (CompanyMailer::blocked($doc->company_id, $to)) {
            throw new RuntimeException("El correo {$to} está bloqueado. Desbloquéalo en Configuración > Desbloqueo de correos.");
        }
        $company = Company::query()->find($doc->company_id);
        $from = $company?->business_name ?: trim("{$company?->first_name} {$company?->last_name}");
        $pdf = $this->pdf($doc);
        [$fromEmail, $fromName] = CompanyMailer::from($doc->company_id, $from);
        $zip = $doc instanceof SalesDocument ? DianService::attachedZip($doc, $pdf) : null;

        try {
            CompanyMailer::mailer($doc->company_id)->send([], [], function (Message $m) use ($doc, $to, $company, $from, $pdf, $zip, $fromEmail, $fromName) {
                $m->from($fromEmail, $fromName)->to($to)->subject($zip
                    ? implode(';', [$company?->nit, $from, \App\Models\DocumentType::query()->whereKey($doc->document_type_id)->value('prefix').$doc->number, ['NC' => '91', 'ND' => '92'][$doc->type] ?? '01', $from, ''])
                    : "{$doc->name} - {$from}");
                if ($company?->email) {
                    $m->replyTo($company->email, $from);
                }
                $m->html(view('mail.document', ['doc' => $doc, 'company' => $from])->render());
                if ($zip) {
                    $m->attach($zip['path'], ['as' => $zip['name'], 'mime' => 'application/zip']);
                } else {
                    $m->attach($pdf, ['as' => "{$doc->name}.pdf", 'mime' => 'application/pdf']);
                }
            });
        } finally {
            @unlink($pdf);
            $zip && @unlink($zip['path']);
        }
        $doc->forceFill(['mail_status' => 'sent'])->save();
        \App\Models\AuditLog::record('mail_sent', $doc, [], ['to' => $to, 'name' => $doc->contact?->name]);
    }

    /** Genera el PDF con Chromium sin interfaz a partir de la vista de impresion firmada. */
    public function pdf(SalesDocument|Receipt $doc): string
    {
        $url = URL::temporarySignedRoute('documents.signed-print', now()->addMinutes(5), ['id' => $doc->id, 'c' => $doc->company_id, 'k' => DocumentLocator::kind($doc)]);
        $file = sys_get_temp_dir().'/doc_'.$doc->id.'_'.bin2hex(random_bytes(4)).'.pdf';
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
