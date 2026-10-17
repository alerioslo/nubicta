<?php

namespace App\Http\Controllers;

use App\Models\SalesDocument;
use App\Services\Documents\DocumentShare;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pagina publica del enlace de "Copiar enlace": muestra el PDF guardado y permite descargarlo junto con el XML. */
class PublicDocumentController extends Controller
{
    public function __invoke(Request $request, int $id): Response
    {
        CurrentCompany::set((int) $request->query('c'));
        $doc = SalesDocument::query()->where('status', '!=', 'annulled')->findOrFail($id);
        $disk = DocumentShare::disk();
        $pdf = DocumentShare::path($doc);
        abort_unless($disk->exists($pdf), 404);
        $xml = collect([$doc->attached_path, $doc->xml_path])->first(fn ($p) => filled($p) && $disk->exists($p));

        return match ($request->query('archivo')) {
            'pdf' => $disk->response($pdf, "{$doc->name}.pdf", [], $request->boolean('ver') ? 'inline' : 'attachment'),
            'xml' => $xml ? $disk->download($xml, basename($xml)) : abort(404),
            default => response()->view('documents.public', [
                'doc' => $doc, 'company' => DocumentController::company($doc->company_id), 'hasXml' => (bool) $xml,
                'pdfUrl' => DocumentShare::url($doc, ['archivo' => 'pdf', 'ver' => 1]),
                'downloadUrl' => DocumentShare::url($doc, ['archivo' => 'pdf']),
                'xmlUrl' => DocumentShare::url($doc, ['archivo' => 'xml']),
            ])->header('X-Robots-Tag', 'noindex, nofollow'),
        };
    }
}
