<?php

namespace App\Jobs;

use App\Models\SalesDocument;
use App\Services\Documents\DocumentMailer;
use App\Services\Mail\CompanyMailer;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Envia al cliente la factura o nota cuando la DIAN la acepta, como hace Siigo.
 * mail_status: queued, sent, failed, no_email (el cliente no tiene correo) o blocked (correo en la lista de bloqueo).
 */
class SendDocumentMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public int $id, public int $companyId) {}

    /** Marca el documento en cola y despacha el envio cuando termine la transaccion. */
    public static function enqueue(SalesDocument $doc): void
    {
        $doc->forceFill(['mail_status' => 'queued'])->save();
        self::dispatch($doc->id, $doc->company_id)->afterCommit();
    }

    public function handle(DocumentMailer $mailer): void
    {
        CurrentCompany::set($this->companyId);
        $doc = SalesDocument::query()->with('contact')->find($this->id);
        if (! $doc || $doc->dian_status !== 'Accepted' || in_array($doc->mail_status, ['sent', 'read'], true)) {
            return;
        }
        $to = trim((string) $doc->contact?->email);
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $doc->forceFill(['mail_status' => 'no_email'])->save();

            return;
        }
        if (CompanyMailer::blocked($doc->company_id, $to)) {
            $doc->forceFill(['mail_status' => 'blocked'])->save();

            return;
        }
        try {
            $mailer->send($doc, $to);
        } catch (TransportExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $doc->forceFill(['mail_status' => 'failed'])->save();
            report($e);
        }
    }

    public function failed(Throwable $e): void
    {
        SalesDocument::query()->whereKey($this->id)->update(['mail_status' => 'failed']);
    }
}
