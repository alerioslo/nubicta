<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Services\Documents\DocumentMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Envio masivo: manda por correo los comprobantes seleccionados. */
class MassSendController extends Controller
{
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
