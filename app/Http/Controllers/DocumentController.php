<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentLocator;
use App\Services\Documents\DocumentReverser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Detalle, anulacion, XML e impresion de cualquier documento (ventas, compras, recibos, comprobantes). */
class DocumentController extends Controller
{
    public function show(string $kind, int $id): Response
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');

        if ($doc instanceof \App\Models\Receipt) {
            return Inertia::render('Documents/ReceiptShow', ['doc' => DocumentLocator::detail($doc), 'company' => self::company($doc->company_id)]);
        }

        return Inertia::render('Documents/Show', ['doc' => DocumentLocator::detail($doc)]);
    }

    public function annul(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        $module = DocumentLocator::module($doc);
        Gate::authorize($module.($module === 'inventario' ? '.editar' : '.anular'));
        app(DocumentReverser::class)->annul($doc);

        return redirect(DocumentLocator::url($doc))->with('success', "Se anuló el documento {$doc->name}.");
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\Receipt, 404);
        Gate::authorize(DocumentLocator::module($doc).'.anular');
        app(DocumentReverser::class)->delete($doc);

        return redirect($doc->type === 'RC' ? '/ventas/recaudos' : '/compras/pagos')->with('success', "Se eliminó el documento {$doc->name}.");
    }

    public function favorite(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\JournalEntry, 404);
        Gate::authorize('contabilidad.ver');
        $doc->forceFill(['favorite' => ! $doc->favorite])->save();

        return back()->with('success', $doc->favorite ? "{$doc->name} quedó en tus comprobantes favoritos." : "{$doc->name} se quitó de favoritos.");
    }

    public function mail(\Illuminate\Http\Request $request, string $kind, int $id, \App\Services\Documents\DocumentMailer $mailer): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument || $doc instanceof \App\Models\Receipt, 404);
        Gate::authorize(DocumentLocator::module($doc).'.ver');
        if (! filter_var($request->input('to'), FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'El correo del destinatario no es válido.');
        }
        try {
            $mailer->send($doc->load('contact'), $request->input('to'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Se envió {$doc->name} a {$request->input('to')}.");
    }

    public function xml(string $kind, int $id): StreamedResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');
        $path = match (request('tipo')) { 'respuesta' => $doc->dian_response_path ?? null, 'contenedor' => $doc->attached_path ?? null, default => $doc->xml_path ?? null };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    public function print(string $kind, int $id): Response
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');

        return Inertia::render('Documents/Print', self::sheet($doc) + ['company' => self::company($doc->company_id)]);
    }

    /** Impresion por lotes desde Envio masivo (?ids=1,2,3). */
    public function batch(\Illuminate\Http\Request $request): Response
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('ids'))));
        abort_if(! $ids || count($ids) > 200, 422, 'Selecciona entre 1 y 200 comprobantes');
        $docs = \App\Models\SalesDocument::query()->whereIn('id', $ids)->orderBy('date')->orderBy('number')->get();

        return Inertia::render('Documents/Print', [
            'docs' => $docs->map(fn ($d) => self::sheet($d))->all(),
            'company' => self::company(\App\Support\CurrentCompany::id()),
        ]);
    }

    /** URL firmada sin sesion que usa Chromium para generar el PDF adjunto del correo. */
    public function signedPrint(\Illuminate\Http\Request $request, int $id): Response
    {
        \App\Support\CurrentCompany::set((int) $request->input('c'));
        $doc = DocumentLocator::find((string) $request->input('k', 'ventas'), $id);

        return Inertia::render('Documents/Print', self::sheet($doc) + ['company' => self::company($doc->company_id), 'bare' => true]);
    }

    /** @return array{doc: array, resolution: mixed} */
    public static function sheet(\Illuminate\Database\Eloquent\Model $doc): array
    {
        return [
            'doc' => DocumentLocator::detail($doc),
            'resolution' => \Illuminate\Support\Facades\DB::table('document_types')->where('id', $doc->document_type_id)
                ->first(['resolution_number', 'resolution_date', 'range_from', 'range_to', 'prefix', 'valid_from', 'valid_to']),
        ];
    }

    public static function company(?int $id): ?array
    {
        $company = $id ? \App\Models\Company::query()->find($id) : null;

        return $company ? [
            'name' => $company->business_name ?: trim("{$company->first_name} {$company->last_name}"), 'trade_name' => $company->trade_name,
            'nit' => $company->nit.($company->dv !== null ? "-{$company->dv}" : ''), 'address' => $company->address,
            'phone' => $company->phone, 'phone2' => $company->phone2, 'email' => $company->email, 'logo' => $company->logo_path ? '/storage/'.$company->logo_path : null,
            'city' => \Illuminate\Support\Facades\DB::table('municipalities')->where('code', $company->municipality_code)->value('name'),
        ] : null;
    }
}
