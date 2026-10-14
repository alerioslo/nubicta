<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\DianInboxDocument;
use App\Services\Purchases\PurchaseInbox;
use App\Services\Purchases\SupplierInvoiceParser;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** "Importar compra o gasto desde XML o ZIP": lee la factura electronica UBL 2.1 del proveedor y muestra la vista previa. */
class XmlImportController extends Controller
{
    public function create(Request $request, PurchaseInbox $inbox): Response
    {
        $doc = $request->input('bandeja') ? DianInboxDocument::query()->find((int) $request->input('bandeja')) : null;

        return Inertia::render('Purchases/ImportXml', ['preview' => $doc ? ['inbox_id' => $doc->id, 'causada' => $doc->status === 'Causada'] + $inbox->parse($doc) : null]);
    }

    public function store(Request $request, SupplierInvoiceParser $parser, PurchaseInbox $inbox): Response
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xml,zip,txt']], [
            'file.required' => 'Selecciona el archivo XML o ZIP de la factura',
            'file.mimes' => 'El archivo debe ser XML o ZIP',
        ]);
        $file = $request->file('file');
        $content = (string) file_get_contents($file->getRealPath());
        try {
            $xmls = strtolower($file->getClientOriginalExtension()) === 'zip' ? $parser->xmlsFromZip($content) : [$content];
            $doc = null;
            foreach ($xmls as $xml) {
                $doc ??= $inbox->ingest($xml, 'archivo');
            }
            if (! $doc) {
                throw new RuntimeException($xmls ? 'El archivo no contiene una factura electrónica UBL 2.1' : 'El ZIP no contiene ningún XML');
            }
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return Inertia::render('Purchases/ImportXml', ['preview' => ['inbox_id' => $doc->id, 'causada' => $doc->status === 'Causada'] + $inbox->parse($doc)]);
    }
}
