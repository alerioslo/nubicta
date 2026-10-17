<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\DianInboxDocument;
use App\Services\Ai\AiClient;
use App\Services\Purchases\InvoiceProductMatcher;
use App\Services\Purchases\PurchaseInbox;
use App\Services\Purchases\SupplierInvoiceParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/** "Importar compra o gasto desde XML o ZIP": lee la factura electronica UBL 2.1 del proveedor y muestra la vista previa. */
class XmlImportController extends Controller
{
    public function create(Request $request, PurchaseInbox $inbox, InvoiceProductMatcher $matcher): Response
    {
        $doc = $request->input('bandeja') ? DianInboxDocument::query()->find((int) $request->input('bandeja')) : null;

        return Inertia::render('Purchases/ImportXml', ['preview' => $doc ? $this->preview($doc, $inbox, $matcher) : null, 'ai' => AiClient::forCurrentCompany()->enabled()]);
    }

    public function store(Request $request, SupplierInvoiceParser $parser, PurchaseInbox $inbox, InvoiceProductMatcher $matcher): Response
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

        return Inertia::render('Purchases/ImportXml', ['preview' => $this->preview($doc, $inbox, $matcher), 'ai' => AiClient::forCurrentCompany()->enabled()]);
    }

    /** Vincula con IA los productos de la factura que no se reconocieron por codigo ni por compras anteriores. */
    public function match(int $id, PurchaseInbox $inbox, InvoiceProductMatcher $matcher): RedirectResponse
    {
        $doc = DianInboxDocument::query()->where('status', 'Pendiente')->findOrFail($id);
        $back = redirect("/compras/importar-xml?bandeja={$doc->id}");
        try {
            $r = $matcher->runAi($doc, $inbox->parse($doc));
        } catch (Throwable $e) {
            return $back->with('error', 'No se pudieron vincular los productos con IA: '.mb_substr($e->getMessage(), 0, 300));
        }

        return $back->with('success', "La IA vinculó {$r['matched']} producto(s)".($r['pending'] ? "; {$r['pending']} línea(s) quedan para elegir a mano en la factura." : '.').' Revisa las sugerencias antes de continuar.');
    }

    private function preview(DianInboxDocument $doc, PurchaseInbox $inbox, InvoiceProductMatcher $matcher): array
    {
        $data = $inbox->parse($doc);

        return ['inbox_id' => $doc->id, 'causada' => $doc->status === 'Causada', 'matches' => (object) $matcher->suggestions($doc, $data), 'ai_at' => $doc->product_matches['at'] ?? null] + $data;
    }
}
