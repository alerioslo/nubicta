<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\PurchaseDocument;
use App\Models\DianInboxDocument;
use App\Models\RadianEvent;
use App\Services\Purchases\PurchaseInbox;
use App\Services\Dian\DianService;
use App\Services\Dian\DianUnavailableException;
use App\Services\Dian\RadianEventBuilder;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Compras > Registro de eventos: eventos RADIAN 030, 032, 033 y 031 sobre las facturas electronicas de los proveedores. */
class RadianEventController extends Controller
{
    public const ID_TYPES = ['13' => 'Cédula de ciudadanía', '22' => 'Cédula de extranjería', '41' => 'Pasaporte', '12' => 'Tarjeta de identidad', '47' => 'Permiso especial de permanencia', '48' => 'Permiso por protección temporal'];

    private const NOT_CREDIT = 'La factura electrónica de venta asociada no fue emitida con forma de pago a crédito. Solo se puede confirmar la recepción de la factura electrónica de venta de tu proveedor cuando esta tiene operaciones a crédito. (Res. DIAN 00085, art. 34 del 08 de abril de 2022 - Decreto 1074 de 2015)';

    public function index(Request $request): Response
    {
        $finished = $request->input('sub') === 'Eventos finalizados';
        $events = RadianEvent::query()
            ->where('finished', $finished)
            ->when($request->input('q'), fn ($q, $t) => $q->where('cufe', 'like', "$t%"))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('registered_at', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('registered_at', '<=', $d))
            ->when($request->input('proveedor'), fn ($q, $t) => $q->where(fn ($w) => $w->where('supplier_name', 'like', "%$t%")->orWhere('supplier_nit', 'like', "$t%")))
            ->when($request->input('recepcion'), fn ($q, $s) => $q->where('reception_status', $s))
            ->when($request->input('mercancia'), fn ($q, $s) => $q->where('goods_status', $s))
            ->when($request->input('aceptacion'), fn ($q, $s) => $q->where('acceptance_status', $s))
            ->orderByDesc('registered_at')->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 10))->withQueryString()
            ->through(fn (RadianEvent $e) => $this->row($e));

        return Inertia::render('Purchases/RadianEvents', [
            'events' => $events,
            'tabs' => DashboardController::tabs('compras', 'Registro de eventos'),
            'filters' => $request->only('q', 'sub', 'desde', 'hasta', 'proveedor', 'recepcion', 'mercancia', 'aceptacion'),
            'idTypes' => self::ID_TYPES,
            'claims' => RadianEventBuilder::CLAIMS,
            'remembered' => RadianEvent::query()->whereNotNull('receiver')->latest('updated_at')->get(['receiver'])
                ->first(fn ($e) => ! empty($e->receiver['remember']))?->receiver,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cufe' => ['required', 'regex:/^[0-9a-fA-F]{96}$/', Rule::unique('radian_events', 'cufe')->where('company_id', CurrentCompany::id())],
        ] + $this->personRules(), [
            'cufe.regex' => 'El CUFE es un código de 96 caracteres que se encuentra en la factura electrónica de venta.',
            'cufe.unique' => 'Esta factura ya está registrada en el registro de eventos.',
        ]);
        $dian = DianService::forCurrent();
        try {
            $invoice = $dian->supplierInvoice(strtolower($data['cufe'])) ?? $this->fromInbox(strtolower($data['cufe']));
        } catch (DianUnavailableException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $event = RadianEvent::create([
            'registered_at' => now()->toDateString(),
            'cufe' => strtolower($data['cufe']),
            'supplier_nit' => $invoice['nit'] ?? null,
            'supplier_dv' => $invoice['dv'] ?? null,
            'supplier_name' => $invoice['name'] ?? null,
            'document_number' => $invoice['number'] ?? null,
            'payment_means' => $invoice['payment_means'] ?? null,
            'invoice_date' => $invoice['date'] ?? null,
            'purchase_document_id' => $invoice ? $this->purchase($invoice) : null,
            'reception_status' => 'Pendiente', 'goods_status' => 'Pendiente', 'acceptance_status' => 'Pendiente',
            'receiver' => $this->person($request), 'events' => [], 'finished' => false,
        ]);
        if (! $invoice) {
            $event->update(['reception_status' => 'Rechazado', 'message' => 'La DIAN no tiene registrada una factura electrónica de venta con este CUFE.']);

            return back()->with('error', $event->message);
        }

        return $this->send($event, '030', 'reception_status', 'Se confirmó la recepción de la factura.');
    }

    /** Si la DIAN no devuelve el XML (p. ej. en habilitación), usa la factura que llegó a la Bandeja DIAN. */
    private function fromInbox(string $cufe): ?array
    {
        $doc = DianInboxDocument::query()->where('cufe', $cufe)->first();
        if (! $doc) {
            return null;
        }
        $d = app(PurchaseInbox::class)->parse($doc);

        return [
            'number' => $d['number'], 'nit' => $d['supplier']['nit'], 'dv' => $d['supplier']['dv'], 'id_type' => $d['supplier']['id_type'],
            'name' => $d['supplier']['name'], 'payment_means' => $d['payment_means'], 'date' => $d['date'], 'vat' => $d['supplier']['vat'],
        ];
    }

    public function goods(Request $request, int $id): RedirectResponse
    {
        $event = RadianEvent::query()->findOrFail($id);
        $request->validate($this->personRules());
        abort_unless($event->reception_status === 'Validado' && $event->goods_status !== 'Validado', 422, 'Primero debes confirmar la recepción de la factura.');
        $event->update(['receiver' => $this->person($request)]);

        return $this->send($event, '032', 'goods_status', 'Se confirmó la recepción de la mercancía / servicio.');
    }

    public function accept(int $id): RedirectResponse
    {
        $event = RadianEvent::query()->findOrFail($id);
        abort_unless($event->goods_status === 'Validado' && $event->acceptance_status === 'Pendiente', 422, 'Primero debes confirmar la recepción de la mercancía / servicio.');

        return $this->send($event, '033', 'acceptance_status', 'Se aceptó la factura.', 'Aceptado');
    }

    public function claim(Request $request, int $id): RedirectResponse
    {
        $event = RadianEvent::query()->findOrFail($id);
        $data = $request->validate(['claim_code' => ['required', Rule::in(array_keys(RadianEventBuilder::CLAIMS))]], ['claim_code.required' => 'Selecciona el motivo del reclamo.']);
        abort_unless($event->goods_status === 'Validado' && $event->acceptance_status === 'Pendiente', 422, 'Primero debes confirmar la recepción de la mercancía / servicio.');
        $event->update(['claim_code' => $data['claim_code']]);

        return $this->send($event, '031', 'acceptance_status', 'Se registró el reclamo de la factura.', 'Reclamado');
    }

    public function destroy(int $id): RedirectResponse
    {
        $event = RadianEvent::query()->findOrFail($id);
        abort_if($event->reception_status === 'Validado', 422, 'Solo se pueden borrar los registros cuya recepción fue rechazada.');
        foreach ($event->events ?? [] as $e) {
            Storage::disk('local')->delete($e['path'] ?? '');
        }
        $event->delete();

        return redirect('/compras/registro-eventos')->with('success', 'Se borró el registro.');
    }

    public function xml(int $id, int $index): StreamedResponse
    {
        $event = RadianEvent::query()->findOrFail($id);
        $path = ($event->events ?? [])[$index]['path'] ?? abort(404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    private function send(RadianEvent $event, string $code, string $column, string $ok, string $okStatus = 'Validado'): RedirectResponse
    {
        try {
            $r = DianService::forCurrent()->sendRadianEvent($event, $code, $code === '031' ? $event->claim_code : null);
        } catch (DianUnavailableException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $message = $r['valid'] ? null : ($event->payment_means !== '2' && $code === '030' ? self::NOT_CREDIT : $r['message']);
        $event->update([
            $column => $r['valid'] ? $okStatus : 'Rechazado',
            'events' => [...($event->events ?? []), ['code' => $code, 'number' => $r['number'], 'cude' => $r['cude'], 'valid' => $r['valid'], 'message' => $r['message'], 'path' => $r['path'], 'at' => now()->toIso8601String()]],
            'message' => $message,
            'finished' => $r['valid'] && in_array($code, ['031', '033'], true),
        ]);

        return $r['valid'] ? back()->with('success', $ok) : back()->with('error', $message);
    }

    private function personRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'id_type' => ['required', Rule::in(array_keys(self::ID_TYPES))], 'id_number' => ['required', 'string', 'max:20'],
            'job' => ['nullable', 'string', 'max:60'], 'department' => ['nullable', 'string', 'max:60'], 'remember' => ['nullable'],
        ];
    }

    private function person(Request $request): array
    {
        return $request->only('first_name', 'last_name', 'id_type', 'id_number', 'job', 'department') + ['remember' => $request->boolean('remember')];
    }

    private function purchase(array $invoice): ?int
    {
        return PurchaseDocument::query()
            ->whereHas('contact', fn ($q) => $q->where('identification', $invoice['nit']))
            ->where(fn ($q) => $q->whereRaw("CONCAT(COALESCE(supplier_prefix,''), supplier_number) = ?", [$invoice['number']])
                ->orWhere('supplier_number', $invoice['number']))
            ->value('id');
    }

    private function row(RadianEvent $e): array
    {
        return [
            'id' => $e->id, 'date' => $e->registered_at->translatedFormat('j M Y'), 'cufe' => $e->cufe, 'nit' => $e->supplier_nit,
            'number' => $e->document_number, 'supplier' => $e->supplier_name,
            'reception' => $e->reception_status, 'goods' => $e->goods_status, 'acceptance' => $e->acceptance_status,
            'final' => $e->acceptance_status !== 'Pendiente' ? $e->acceptance_status : $e->reception_status,
            'ref' => $e->external_ref ?? (string) $e->id, 'message' => $e->message, 'receiver' => $e->receiver,
            'claim' => $e->claim_code ? RadianEventBuilder::CLAIMS[$e->claim_code] ?? null : null,
            'purchase_url' => ($p = $e->purchase_document_id ? PurchaseDocument::query()->find($e->purchase_document_id) : null) ? \App\Services\Documents\DocumentLocator::url($p) : null,
            'events' => collect($e->events ?? [])->map(fn ($v, $i) => [
                'label' => $v['code'].' '.(RadianEventBuilder::EVENTS[$v['code']] ?? ''), 'number' => $v['number'] ?? null, 'valid' => $v['valid'] ?? false,
                'message' => $v['message'] ?? null, 'at' => isset($v['at']) ? \Illuminate\Support\Carbon::parse($v['at'])->setTimezone('America/Bogota')->format('d/m/Y H:i') : null,
                'xml' => "/compras/registro-eventos/{$e->id}/xml/$i",
            ])->values()->all(),
        ];
    }
}
