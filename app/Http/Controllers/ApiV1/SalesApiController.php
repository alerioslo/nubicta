<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\DocumentFormController;
use App\Models\DocumentType;
use App\Models\SalesDocument;
use App\Services\Dian\CorrectionConcepts;
use App\Services\Dian\DianService;
use App\Services\Documents\DocumentMailer;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\DocumentShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Facturas de venta, notas crédito, notas débito y cotizaciones: /facturas, /notas-credito, /notas-debito, /cotizaciones. */
class SalesApiController extends ApiBaseController
{
    use BuildsTradeDocuments;

    public const KINDS = [
        'facturas' => ['doc' => 'FV', 'form' => 'ventas/facturas/crear', 'label' => 'factura'],
        'notas-credito' => ['doc' => 'NC', 'form' => 'ventas/notas-credito/crear', 'label' => 'nota crédito'],
        'notas-debito' => ['doc' => 'ND', 'form' => 'ventas/notas-debito/crear', 'label' => 'nota débito'],
        'cotizaciones' => ['doc' => 'CT', 'form' => 'ventas/cotizaciones/crear', 'label' => 'cotización'],
    ];

    public function index(Request $request, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.ver');
        $type = $clase === 'facturas' && in_array($request->input('tipo'), ['NC', 'ND'], true) ? $request->input('tipo') : self::KINDS[$clase]['doc'];
        $q = SalesDocument::query()->with('contact')->where('type', $type);
        $this->filter($q, $request);

        return $this->paged($q->orderByDesc('date')->orderByDesc('number')->paginate($this->perPage($request)), fn ($d) => ApiPresenter::sale($d));
    }

    public function show(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.ver');

        return response()->json(ApiPresenter::sale($this->find($clase, $id)->load('items', 'contact'), true));
    }

    public function store(Request $request, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.crear');
        [$doc, $dian] = $this->save($request, $clase);

        return response()->json(['mensaje' => "Se creó {$doc->name}.", 'dian' => $dian, 'data' => ApiPresenter::sale($doc->fresh(['items', 'contact']), true)], 201);
    }

    public function update(Request $request, int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.editar');
        $current = $this->find($clase, $id);
        if ($reason = app(DocumentReverser::class)->blocker($current)) {
            return response()->json(['message' => $reason], 409);
        }
        [$doc, $dian] = $this->save($request, $clase, $current);

        return response()->json(['mensaje' => "Se actualizó {$doc->name}.", 'dian' => $dian, 'data' => ApiPresenter::sale($doc->fresh(['items', 'contact']), true)]);
    }

    public function destroy(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.anular');
        $doc = $this->find($clase, $id);
        $this->attempt(fn () => app(DocumentReverser::class)->deleteSale($doc));

        return response()->json(['mensaje' => "Se eliminó {$doc->name}; su consecutivo queda disponible."]);
    }

    public function annul(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.anular');
        $doc = $this->find($clase, $id);
        $this->attempt(fn () => app(DocumentReverser::class)->annul($doc));

        return response()->json(['mensaje' => "Se anuló {$doc->name}.", 'data' => ApiPresenter::sale($doc->fresh('contact'))]);
    }

    public function pdf(int $id, string $clase): BinaryFileResponse
    {
        $this->authorizeApi('ventas.imprimir');
        $doc = $this->find($clase, $id);
        $path = $this->attempt(fn () => app(DocumentMailer::class)->pdf($doc));

        return response()->download($path, "{$doc->name}.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    public function xml(Request $request, int $id, string $clase): StreamedResponse|JsonResponse
    {
        $this->authorizeApi('ventas.ver');
        $doc = $this->find($clase, $id);
        $path = match ($request->input('tipo')) {
            'respuesta' => $doc->dian_response_path, 'contenedor' => $doc->attached_path, default => $doc->xml_path
        };
        $disk = DocumentShare::disk();
        if (! $path || ! $disk->exists($path)) {
            return response()->json(['message' => "{$doc->name} no tiene XML ".($request->input('tipo') ?: 'del documento').'. Solo existe para documentos electrónicos enviados a la DIAN.'], 404);
        }

        return $disk->download($path, basename($path), ['Content-Type' => 'application/xml']);
    }

    public function mail(Request $request, int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.imprimir');
        $request->validate(['correo' => ['nullable', 'email']]);
        $doc = $this->find($clase, $id);
        if (! DocumentMailer::configured()) {
            return response()->json(['message' => 'El envío de correo no está configurado en Configuración > Correo.'], 409);
        }
        $to = $request->input('correo') ?: $doc->contact?->email;
        if (! $to) {
            return response()->json(['message' => 'El cliente no tiene correo; envía "correo" en el cuerpo.'], 422);
        }
        $this->attempt(fn () => app(DocumentMailer::class)->send($doc, $to));

        return response()->json(['mensaje' => "Se envió {$doc->name} a {$to}."]);
    }

    /** Envía (o reenvía) a la DIAN un documento electrónico en borrador, pendiente o rechazado. */
    public function sendDian(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.crear');
        $doc = $this->find($clase, $id);
        if (! DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            return response()->json(['message' => "{$doc->name} no es un documento electrónico."], 422);
        }
        if ($doc->dian_status === 'Accepted') {
            return response()->json(['message' => "La DIAN ya aceptó {$doc->name}.", 'data' => $this->dianState($doc)], 409);
        }
        $message = app(DocumentFormController::class)->emit($doc);

        return response()->json(['mensaje' => $message, 'data' => $this->dianState($doc->fresh())]);
    }

    /** Estado DIAN con sus errores; con ?consultar=1 vuelve a consultar el estado en la DIAN. */
    public function dian(Request $request, int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('ventas.ver');
        $doc = $this->find($clase, $id);
        if ($request->boolean('consultar') && $doc->cufe) {
            $this->attempt(fn () => DianService::forCurrent()->refresh($doc));
            $doc->refresh();
        }

        return response()->json($this->dianState($doc));
    }

    /** @return array{0: SalesDocument, 1: ?string} */
    private function save(Request $request, string $clase, ?SalesDocument $current = null): array
    {
        $kind = self::KINDS[$clase];
        $input = $request->all();
        $data = $this->tradeData($input, $kind['doc'], false);
        $data['document_type'] = $current?->document_type_id ?? $this->documentType($input, $kind['doc']);
        if (in_array($kind['doc'], ['NC', 'ND'], true)) {
            $data += $this->correction($input, $kind, $data['document_type']);
        }
        $doc = $this->registerTrade($kind['form'], $data, $current);
        if ($request->boolean('enviar_correo') && ! DocumentType::query()->find($doc->document_type_id)?->isElectronic() && DocumentMailer::configured() && $doc->contact?->email) {
            rescue(fn () => app(DocumentMailer::class)->send($doc, $doc->contact->email));
        }

        return [$doc, $this->emitIfElectronic($doc, $request->input('enviar_dian', true) !== false && $request->input('enviar_dian') !== 'false')];
    }

    /** Factura que corrige la nota y motivo DIAN, con las mismas reglas de la pantalla. */
    private function correction(array $input, array $kind, int|string|null $typeId): array
    {
        $reasons = $kind['doc'] === 'NC' ? CorrectionConcepts::NC : CorrectionConcepts::ND;
        $motivo = (string) ($input['motivo'] ?? '');
        if (! array_key_exists($motivo, $reasons)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo DIAN: '.collect($reasons)->map(fn ($v, $k) => "$k = $v")->implode('; ').'.']);
        }
        $invoice = null;
        if (! empty($input['factura_id']) || ! empty($input['factura'])) {
            $invoice = SalesDocument::query()->where('type', 'FV')
                ->when(! empty($input['factura_id']), fn ($q) => $q->whereKey((int) $input['factura_id']), fn ($q) => $q->where('name', $input['factura']))->first()
                ?? throw ValidationException::withMessages(['factura_id' => 'No existe la factura indicada.']);
        }
        $type = is_int($typeId) ? DocumentType::query()->find($typeId) : null;
        if ($type?->isElectronic() && ! $invoice) {
            throw ValidationException::withMessages(['factura_id' => "La {$kind['label']} electrónica debe indicar la factura que corrige (\"factura_id\" o \"factura\": \"FV-1-25\")."]);
        }
        if ($type && ! $type->isElectronic() && $invoice && filled($invoice->cufe)) {
            throw ValidationException::withMessages(['tipo_documento_id' => "La factura {$invoice->name} es electrónica: usa un tipo de {$kind['label']} electrónico."]);
        }

        return ['related_id' => $invoice?->id, 'reason' => $motivo];
    }

    private function dianState(SalesDocument $doc): array
    {
        $rows = DB::table('dian_transmissions')->where('sales_document_id', $doc->id)->orderByDesc('id')->limit(10)
            ->get(['operation', 'environment', 'simulated', 'track_id', 'is_valid', 'status_code', 'status_message', 'errors', 'created_at']);

        return [
            'id' => $doc->id, 'numero' => $doc->name, 'estado_dian' => $doc->dian_status, 'mensaje_dian' => $doc->dian_message, 'cufe' => $doc->cufe,
            'errores' => array_values(array_filter((array) json_decode((string) ($rows->first()?->errors ?? '[]'), true))),
            'transmisiones' => $rows->map(fn ($t) => [
                'operacion' => $t->operation, 'ambiente' => $t->environment, 'simulado' => (bool) $t->simulated, 'track_id' => $t->track_id, 'valido' => (bool) $t->is_valid,
                'codigo' => $t->status_code, 'mensaje' => $t->status_message, 'errores' => json_decode((string) $t->errors, true) ?: [], 'fecha' => $t->created_at,
            ])->values(),
        ];
    }

    private function find(string $clase, int $id): SalesDocument
    {
        return SalesDocument::query()->where('type', self::KINDS[$clase]['doc'])->find($id)
            ?? abort(404, 'No existe la '.self::KINDS[$clase]['label']." con id {$id}.");
    }

    private function filter($q, Request $request): void
    {
        $q->when($request->filled('desde'), fn ($q) => $q->whereDate('date', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('date', '<=', $request->input('hasta')))
            ->when($request->filled('numero'), fn ($q) => $q->where('name', $request->input('numero')))
            ->when($request->filled('tipo_documento_id'), fn ($q) => $q->where('document_type_id', $request->integer('tipo_documento_id')))
            ->when($request->filled('cliente_id'), fn ($q) => $q->where('contact_id', $request->integer('cliente_id')))
            ->when($request->filled('identificacion'), fn ($q) => $q->whereHas('contact', fn ($c) => $c->where('identification', preg_replace('/\D/', '', (string) $request->input('identificacion')))))
            ->when($request->filled('estado'), fn ($q) => $request->input('estado') === 'anulado' ? $q->where('status', 'annulled') : $q->where('status', '!=', 'annulled'))
            ->when($request->filled('estado_dian'), fn ($q) => $q->where('dian_status', $request->input('estado_dian')))
            ->when($request->boolean('con_saldo'), fn ($q) => $q->where('balance', '>', 0)->where('status', '!=', 'annulled'))
            ->when($request->filled('creado_desde'), fn ($q) => $q->where('created_at', '>=', $request->input('creado_desde')))
            ->when($request->filled('actualizado_desde'), fn ($q) => $q->where('updated_at', '>=', $request->input('actualizado_desde')));
    }
}
