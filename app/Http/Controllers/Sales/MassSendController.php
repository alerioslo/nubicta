<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Services\Documents\DocumentMailer;
use App\Http\Controllers\DashboardController;
use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Envio masivo: manda por correo los comprobantes seleccionados. */
class MassSendController extends Controller
{
    public const CLASSES = ['FV' => 'Factura/Ingreso Nube', 'POS' => 'Factura POS', 'NC' => 'Nota crédito', 'ND' => 'Nota débito (Ventas)'];

    public const DIAN = ['No enviado', 'No aplica', 'Rechazado', 'Aceptado', 'Fallido', 'Fecha inválida'];

    public const MAIL = ['Enviado' => ['sent', 'read'], 'En proceso de envío' => ['queued'], 'Envío fallido' => ['failed', 'failed_delivery', 'error', 'blocked'], 'No enviado' => ['not_sent', 'no_email']];

    public function index(Request $request): Response
    {
        $types = DocumentType::query()->whereIn('type', ['FV', 'NC', 'ND'])->where('active', true)->orderBy('id')->get(['id', 'type', 'name', 'electronic_type']);
        $from = $request->input('desde', now()->startOfMonth()->toDateString());
        $to = $request->input('hasta', now()->endOfMonth()->toDateString());
        $classes = (array) $request->input('clases', array_keys(self::CLASSES));
        $typeIds = array_map('intval', (array) $request->input('tipos', $types->pluck('id')->all()));
        $dian = (array) $request->input('dian', self::DIAN);
        $electronic = $types->filter(fn ($t) => $t->electronic_type !== 'NoElectronic')->pluck('id')->all();

        $docs = null;
        if ($request->boolean('buscar')) {
            $docs = SalesDocument::query()->with('contact:id,name,identification,branch_office,email')
                ->whereIn('type', $classes)
                ->where(fn ($q) => $q->whereIn('document_type_id', $typeIds)->orWhereNull('document_type_id'))
                ->whereBetween('date', [$from, $to])
                ->when($request->input('q'), fn ($q, $t) => $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
                ->orderBy('date')->orderBy('created_at')->orderBy('id')
                ->get()
                ->map(fn (SalesDocument $d) => [
                    'id' => $d->id,
                    'class' => self::CLASSES[$d->type] ?? $d->type,
                    'name' => $d->name,
                    'url' => "/documentos/ventas/{$d->id}",
                    'date' => $d->date->format('d/m/Y'),
                    'identification' => $d->contact?->identification,
                    'branch' => $d->contact?->branch_office ? (string) $d->contact->branch_office : '',
                    'contact' => $d->contact?->name,
                    'email' => $d->contact?->email,
                    'total' => (float) $d->total,
                    'mail' => collect(self::MAIL)->search(fn ($v) => in_array($d->mail_status, $v, true)) ?: 'No enviado',
                    'dian' => match (true) {
                        $d->dian_status === 'Accepted' => 'Aceptado',
                        $d->dian_status === 'Rejected' => 'Rechazado',
                        $d->dian_status === 'Failed' => 'Fallido',
                        $d->document_type_id && ! in_array($d->document_type_id, $electronic, true) => 'No aplica',
                        default => 'No enviado',
                    },
                ])
                ->filter(fn ($r) => in_array($r['dian'], $dian, true) && (! $request->input('correo') || $r['mail'] === $request->input('correo')))
                ->values();
        }

        return Inertia::render('Sales/MassSend', [
            'docs' => $docs,
            'classes' => self::CLASSES,
            'types' => $types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'dianOptions' => self::DIAN,
            'mailOptions' => array_keys(self::MAIL),
            'years' => range((int) substr((string) SalesDocument::query()->min('date'), 0, 4) ?: (int) now()->year, (int) now()->year),
            'filters' => ['q' => $request->input('q', ''), 'desde' => $from, 'hasta' => $to, 'clases' => $classes, 'tipos' => $typeIds, 'dian' => $dian, 'correo' => $request->input('correo', '')],
            'tabs' => DashboardController::tabs('ventas', 'Envío masivo'),
        ]);
    }

    public function send(Request $request, DocumentMailer $mailer): RedirectResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer']], [
            'ids.required' => 'Selecciona al menos un comprobante', 'ids.max' => 'Puedes enviar máximo 50 comprobantes a la vez',
        ]);
        if (! DocumentMailer::configured()) {
            return back()->with('error', 'El correo de salida (SMTP) no está configurado en el servidor. Pide al administrador los datos del servidor de correo.');
        }

        $sent = 0;
        $errors = [];
        foreach (SalesDocument::query()->with('contact:id,name,email')->whereIn('id', $request->input('ids'))->get() as $doc) {
            try {
                $mailer->send($doc);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
                $errors[] = $e->getMessage();
            }
        }
        $msg = "Se enviaron {$sent} comprobante(s) por correo.";

        return back()->with($errors ? 'error' : 'success', $errors ? $msg.' Errores: '.implode(' ', array_slice($errors, 0, 5)) : $msg);
    }
}
