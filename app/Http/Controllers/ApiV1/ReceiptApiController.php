<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\DocumentFormController;
use App\Models\DocumentType;
use App\Models\JournalEntry;
use App\Models\Receipt;
use App\Services\Documents\DocumentMailer;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\ReceiptRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Recibos de caja (/recibos-caja), recibos de pago o egresos (/recibos-pago) y comprobantes contables (/comprobantes). */
class ReceiptApiController extends ApiBaseController
{
    public const KINDS = [
        'recibos-caja' => ['type' => 'RC', 'module' => 'ventas', 'party' => 'cliente', 'label' => 'recibo de caja'],
        'recibos-pago' => ['type' => 'RP', 'module' => 'compras', 'party' => 'proveedor', 'label' => 'recibo de pago'],
    ];

    public function index(Request $request, string $clase): JsonResponse
    {
        $kind = self::KINDS[$clase];
        $this->authorizeApi($kind['module'].'.ver');
        $q = Receipt::query()->with('contact')->where('type', $kind['type']);
        $this->dates($q, $request)
            ->when($request->filled('tercero_id'), fn ($q) => $q->where('contact_id', $request->integer('tercero_id')))
            ->when($request->filled('identificacion'), fn ($q) => $q->whereHas('contact', fn ($c) => $c->where('identification', preg_replace('/\D/', '', (string) $request->input('identificacion')))));

        return $this->paged($q->orderByDesc('date')->orderByDesc('number')->paginate($this->perPage($request)), fn ($r) => ApiPresenter::receipt($r));
    }

    public function show(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi(self::KINDS[$clase]['module'].'.ver');

        return response()->json(ApiPresenter::receipt($this->find($clase, $id)->load('items', 'contact'), true));
    }

    public function store(Request $request, string $clase): JsonResponse
    {
        $kind = self::KINDS[$clase];
        $this->authorizeApi($kind['module'].'.crear');
        $input = $request->all();
        Validator::make($input, [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'tipo' => ['nullable', 'in:abono,anticipo,otro'.($kind['type'] === 'RP' ? ',avanzado' : '')],
            'forma_pago_id' => [$kind['type'] === 'RC' ? 'required' : 'required_without:cuenta_bancaria_id', 'nullable', 'integer'],
            'cuenta_bancaria_id' => ['nullable', 'integer'],
            'valor' => ['nullable', 'numeric', 'gt:0'],
            'documentos' => ['nullable', 'array'],
            'documentos.*.documento_id' => ['required', 'integer'],
            'documentos.*.valor' => ['required', 'numeric', 'min:0'],
            'documentos.*.retenciones' => ['nullable', 'array'],
            'documentos.*.retenciones.*.impuesto_id' => ['required', 'integer'],
            'documentos.*.retenciones.*.base' => ['nullable', 'numeric', 'min:0'],
            'documentos.*.retenciones.*.valor' => ['required', 'numeric', 'gt:0'],
            'documentos.*.descuentos' => ['nullable', 'array'],
            'documentos.*.descuentos.*.concepto_id' => ['required', 'integer'],
            'documentos.*.descuentos.*.valor' => ['required', 'numeric', 'gt:0'],
            'concepto_id' => ['nullable', 'integer'],
            'lineas' => ['nullable', 'array'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
        ], ['fecha.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.'])->validate();

        $mode = ['abono' => 'deuda', 'anticipo' => 'anticipo', 'otro' => 'otro', 'avanzado' => 'avanzado'][$input['tipo'] ?? 'abono'];
        $items = collect($input['documentos'] ?? [])->map(fn ($d) => [
            'document_id' => (int) $d['documento_id'], 'value' => (float) $d['valor'],
            'taxes' => collect($d['retenciones'] ?? [])->map(fn ($t) => ['tax_id' => $t['impuesto_id'], 'base' => $t['base'] ?? 0, 'value' => $t['valor']])->all(),
            'discounts' => collect($d['descuentos'] ?? [])->map(fn ($c) => ['concept_id' => $c['concepto_id'], 'value' => $c['valor']])->all(),
        ])->all();
        if ($mode === 'deuda' && ! $items) {
            throw ValidationException::withMessages(['documentos' => 'Indica los documentos a abonar: [{"documento_id": 10, "valor": 50000}]. Consulta los pendientes en GET /cuentas-por-'.($kind['type'] === 'RC' ? 'cobrar' : 'pagar').'.']);
        }
        if ($mode !== 'deuda' && $mode !== 'avanzado' && empty($input['valor'])) {
            throw ValidationException::withMessages(['valor' => 'Indica el valor del recibo.']);
        }
        $data = [
            'date' => $input['fecha'], 'contact_id' => $this->contactFrom($input, $kind['party'])->id, 'mode' => $mode, 'items' => $items,
            'value' => $input['valor'] ?? array_sum(array_column($items, 'value')), 'concept_id' => $input['concepto_id'] ?? null,
            'payment_method_id' => $input['forma_pago_id'] ?? null, 'bank_id' => $input['cuenta_bancaria_id'] ?? null,
            'document_type' => $this->documentType($input, $kind['type'] === 'RC' ? 'RC' : 'CE'), 'observations' => $input['observaciones'] ?? null,
            'lines' => collect($input['lineas'] ?? [])->values()->map(fn ($l, $n) => [
                'account_id' => $this->accountFrom($l, "lineas.$n"), 'debit' => $l['debito'] ?? 0, 'credit' => $l['credito'] ?? 0,
                'document_id' => $l['documento_id'] ?? null, 'description' => $l['descripcion'] ?? null,
            ])->all(),
        ];
        $receipt = $this->attempt(fn () => $kind['type'] === 'RC'
            ? app(ReceiptRegistrar::class)->cash($data)
            : app(ReceiptRegistrar::class)->payment($data, DocumentFormController::FALLBACK_TYPES['CE']));

        return response()->json(['mensaje' => "Se creó {$receipt->name}.", 'data' => ApiPresenter::receipt($receipt->fresh(['items', 'contact']), true)], 201);
    }

    public function destroy(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi(self::KINDS[$clase]['module'].'.anular');
        $doc = $this->find($clase, $id);
        $this->attempt(fn () => app(DocumentReverser::class)->delete($doc));

        return response()->json(['mensaje' => "Se eliminó {$doc->name}; los saldos de los documentos abonados se restablecieron."]);
    }

    public function annul(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi(self::KINDS[$clase]['module'].'.anular');
        $doc = $this->find($clase, $id);
        $this->attempt(fn () => app(DocumentReverser::class)->annul($doc));

        return response()->json(['mensaje' => "Se anuló {$doc->name}.", 'data' => ApiPresenter::receipt($doc->fresh('contact'))]);
    }

    public function pdf(int $id, string $clase): BinaryFileResponse
    {
        $this->authorizeApi(self::KINDS[$clase]['module'].'.ver');
        $doc = $this->find($clase, $id);
        $path = $this->attempt(fn () => app(DocumentMailer::class)->pdf($doc));

        return response()->download($path, "{$doc->name}.pdf", ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    public function mail(Request $request, int $id, string $clase): JsonResponse
    {
        $this->authorizeApi(self::KINDS[$clase]['module'].'.ver');
        $request->validate(['correo' => ['nullable', 'email']]);
        $doc = $this->find($clase, $id);
        $to = $request->input('correo') ?: $doc->contact?->email;
        if (! DocumentMailer::configured() || ! $to) {
            return response()->json(['message' => ! $to ? 'El tercero no tiene correo; envía "correo" en el cuerpo.' : 'El envío de correo no está configurado.'], 422);
        }
        $this->attempt(fn () => app(DocumentMailer::class)->send($doc, $to));

        return response()->json(['mensaje' => "Se envió {$doc->name} a {$to}."]);
    }

    public function journals(Request $request): JsonResponse
    {
        $this->authorizeApi('contabilidad.ver');
        $q = $this->journalQuery()->when($request->filled('tipo_documento_id'), fn ($q) => $q->where('document_type_id', $request->integer('tipo_documento_id')));

        return $this->paged($this->dates($q, $request)->orderByDesc('date')->orderByDesc('number')->paginate($this->perPage($request)), fn ($j) => ApiPresenter::journal($j));
    }

    public function journal(int $id): JsonResponse
    {
        $this->authorizeApi('contabilidad.ver');

        return response()->json(ApiPresenter::journal($this->journalQuery()->findOrFail($id)->load('lines'), true));
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $this->authorizeApi('contabilidad.crear');
        $input = $request->all();
        Validator::make($input, [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'lineas' => ['required', 'array', 'min:2'],
            'lineas.*.debito' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.credito' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:500'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
        ], ['lineas.min' => 'El comprobante necesita al menos dos líneas.'])->validate();

        $lines = collect($input['lineas'])->values()->map(function ($l, $n) {
            $line = ['account_id' => $this->accountFrom($l, "lineas.$n"), 'description' => $l['descripcion'] ?? null,
                'debit' => (float) ($l['debito'] ?? 0), 'credit' => (float) ($l['credito'] ?? 0),
                'contact_id' => (! empty($l['tercero_id']) || ! empty($l['tercero'])) ? $this->contactFrom($l, 'tercero')->id : null];
            if (! empty($l['cruzar_documento_id'])) {
                $line['due'] = ['mode' => 'cruzar', 'document_id' => (int) $l['cruzar_documento_id']];
            } elseif (! empty($l['vencimiento'])) {
                $line['due'] = ['mode' => 'nuevo', 'prefix' => $l['vencimiento']['prefijo'] ?? 'CC', 'number' => $l['vencimiento']['numero'] ?? null,
                    'quote' => $l['vencimiento']['cuota'] ?? 1, 'due_date' => $l['vencimiento']['fecha'] ?? null];
            }
            if (! empty($l['inventario'])) {
                $product = $this->productFrom($l['inventario'], $n);
                $line['inventory'] = ['product_id' => $product?->id, 'quantity' => (float) ($l['inventario']['cantidad'] ?? 0),
                    'type' => ($l['inventario']['tipo'] ?? 'entrada') === 'salida' ? 'Salida' : 'Entrada'];
            }

            return $line;
        })->all();
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        if ($debit <= 0 || abs($debit - round(array_sum(array_column($lines, 'credit')), 2)) > 0.009) {
            throw ValidationException::withMessages(['lineas' => 'Los débitos y los créditos deben ser iguales y mayores a cero.']);
        }
        $entry = $this->attempt(fn () => app(JournalRegistrar::class)->journal([
            'date' => $input['fecha'], 'document_type' => $this->documentType($input, 'CC'), 'observations' => $input['observaciones'] ?? null, 'items' => $lines,
        ]));

        return response()->json(['mensaje' => "Se creó el comprobante {$entry->name}.", 'data' => ApiPresenter::journal($entry->fresh('lines'), true)], 201);
    }

    public function destroyJournal(int $id): JsonResponse
    {
        $this->authorizeApi('contabilidad.anular');
        $entry = $this->journalQuery()->findOrFail($id);
        $this->attempt(fn () => app(DocumentReverser::class)->deleteJournal($entry));

        return response()->json(['mensaje' => "Se eliminó el comprobante {$entry->name}."]);
    }

    public function annulJournal(int $id): JsonResponse
    {
        $this->authorizeApi('contabilidad.anular');
        $entry = $this->journalQuery()->findOrFail($id);
        $this->attempt(fn () => app(DocumentReverser::class)->annul($entry));

        return response()->json(['mensaje' => "Se anuló el comprobante {$entry->name}.", 'data' => ApiPresenter::journal($entry->fresh())]);
    }

    /** Comprobantes contables elaborados (tipo CC), sin los asientos automáticos de facturas, recibos o nómina. */
    private function journalQuery()
    {
        return JournalEntry::query()->whereNull('source_type')
            ->whereIn('document_type_id', DocumentType::query()->where('type', 'CC')->pluck('id'));
    }

    private function accountFrom(array $line, string $field): int
    {
        if (! empty($line['cuenta_id'])) {
            return DB::table('accounts')->where('id', (int) $line['cuenta_id'])->exists() ? (int) $line['cuenta_id']
                : throw ValidationException::withMessages(["$field.cuenta_id" => "No existe la cuenta con id {$line['cuenta_id']}."]);
        }
        if (! empty($line['cuenta'])) {
            return (int) (DB::table('accounts')->where('code', (string) $line['cuenta'])->value('id')
                ?? throw ValidationException::withMessages(["$field.cuenta" => "No existe la cuenta {$line['cuenta']}. Consulta GET /cuentas."]));
        }
        throw ValidationException::withMessages([$field => 'Indica "cuenta" (código PUC, ej. "110505") o "cuenta_id".']);
    }

    private function dates($q, Request $request)
    {
        return $q->when($request->filled('desde'), fn ($q) => $q->whereDate('date', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('date', '<=', $request->input('hasta')))
            ->when($request->filled('numero'), fn ($q) => $q->where('name', $request->input('numero')))
            ->when($request->filled('estado'), fn ($q) => $request->input('estado') === 'anulado' ? $q->where('status', 'annulled') : $q->where('status', '!=', 'annulled'))
            ->when($request->filled('creado_desde'), fn ($q) => $q->where('created_at', '>=', $request->input('creado_desde')));
    }

    private function find(string $clase, int $id): Receipt
    {
        return Receipt::query()->where('type', self::KINDS[$clase]['type'])->find($id)
            ?? abort(404, 'No existe el '.self::KINDS[$clase]['label']." con id {$id}.");
    }
}
