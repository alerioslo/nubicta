<?php

namespace App\Jobs;

use App\Models\PayrollDocument;
use App\Services\Payroll\PayrollMailer;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Envio masivo de documentos de nomina electronica al correo de cada empleado. */
class SendPayrollDocumentMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $id, public int $companyId) {}

    public function handle(PayrollMailer $mailer): void
    {
        CurrentCompany::set($this->companyId);
        $doc = PayrollDocument::query()->find($this->id);
        if (! $doc) {
            return;
        }
        try {
            $mailer->send($doc);
        } catch (\RuntimeException $e) {
            $doc->forceFill(['mail_status' => 'Envío fallido'])->save();
            report($e);
        }
    }
}
