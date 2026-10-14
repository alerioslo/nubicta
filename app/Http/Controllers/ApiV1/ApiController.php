<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Support\ColombianNit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API /api/v1 para tiendas en línea u otras plataformas (llave en Configuración > Credenciales de integración). */
class ApiController extends Controller
{
    public function contacts(Request $request): JsonResponse
    {
        $page = Contact::query()->search($request->input('q'))->role($request->input('tipo'))
            ->when($request->input('identificacion'), fn ($q, $id) => $q->where('identification', $id))
            ->orderBy('name')->paginate(min(100, max(1, $request->integer('por_pagina', 50))));

        return $this->paged($page, fn (Contact $c) => $this->contact($c));
    }

    private function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id, 'tipo_persona' => $contact->person_type, 'tipo_identificacion' => $contact->identification_type,
            'identificacion' => $contact->identification, 'dv' => $contact->dv, 'sucursal' => (int) $contact->branch_office, 'nombre' => $contact->name,
            'nombre_comercial' => $contact->trade_name, 'correo' => $contact->email, 'telefono' => $contact->mobile ?: $contact->phone,
            'direccion' => $contact->address, 'ciudad' => $contact->municipality_code, 'responsable_iva' => (bool) $contact->vat_responsible,
            'roles' => array_keys(array_filter(array_map(fn ($col) => (bool) $contact->{$col}, Contact::ROLES))),
            'dias_credito' => (int) $contact->credit_days, 'activo' => (bool) $contact->active,
        ];
    }

    public function showContact(int $id): JsonResponse
    {
        return response()->json($this->contact(Contact::query()->findOrFail($id)));
    }

    public function storeContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo_persona' => ['required', Rule::in(['natural', 'juridica'])],
            'tipo_identificacion' => ['required', Rule::in(['13', '31', '22', '41', '42', '11', '12', '21', '47', '48', '50', '91'])],
            'identificacion' => ['required', 'string', 'max:20'],
            'nombres' => ['required_if:tipo_persona,natural', 'nullable', 'string', 'max:120'],
            'apellidos' => ['required_if:tipo_persona,natural', 'nullable', 'string', 'max:120'],
            'razon_social' => ['required_if:tipo_persona,juridica', 'nullable', 'string', 'max:190'],
            'correo' => ['nullable', 'email', 'max:190'], 'telefono' => ['nullable', 'string', 'max:30'], 'direccion' => ['nullable', 'string', 'max:190'],
            'ciudad' => ['nullable', 'exists:municipalities,code'], 'roles' => ['nullable', 'array'], 'roles.*' => [Rule::in(array_keys(Contact::ROLES))],
            'responsable_iva' => ['nullable', 'boolean'],
        ]);
        $id = preg_replace('/\D/', '', $data['identificacion']) ?: $data['identificacion'];
        if (Contact::query()->where('identification', $id)->exists()) {
            return response()->json(['message' => "Ya existe un tercero con la identificación $id."], 422);
        }
        $natural = $data['tipo_persona'] === 'natural';
        $roles = $data['roles'] ?? ['cliente'];
        $contact = Contact::create([
            'person_type' => $data['tipo_persona'], 'identification_type' => $data['tipo_identificacion'], 'identification' => $id,
            'dv' => $data['tipo_identificacion'] === '31' ? ColombianNit::verificationDigit($id) : null, 'branch_office' => 0,
            'first_name' => $natural ? $data['nombres'] : null, 'last_name' => $natural ? $data['apellidos'] : null,
            'business_name' => $natural ? null : $data['razon_social'],
            'name' => $natural ? mb_strtoupper(trim("{$data['nombres']} {$data['apellidos']}")) : $data['razon_social'],
            'email' => $data['correo'] ?? null, 'mobile' => $data['telefono'] ?? null, 'address' => $data['direccion'] ?? null,
            'municipality_code' => $data['ciudad'] ?? null, 'department_code' => isset($data['ciudad']) ? substr($data['ciudad'], 0, 2) : null, 'country_code' => 'CO',
            'vat_responsible' => (bool) ($data['responsable_iva'] ?? false), 'active' => true,
            ...collect(Contact::ROLES)->mapWithKeys(fn ($col, $role) => [$col => in_array($role, $roles, true)])->all(),
        ]);

        return response()->json($this->contact($contact), 201);
    }

    public function products(Request $request): JsonResponse
    {
        $page = Product::query()->search($request->input('q'))->with(['prices', 'taxes:id,name,percentage'])
            ->when($request->has('activo'), fn ($q) => $q->where('active', $request->boolean('activo')))
            ->orderBy('code')->paginate(min(100, max(1, $request->integer('por_pagina', 50))));

        return $this->paged($page, fn (Product $p) => $this->product($p));
    }

    public function showProduct(int $id): JsonResponse
    {
        return response()->json($this->product(Product::query()->with(['prices', 'taxes:id,name,percentage'])->findOrFail($id)));
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = SalesDocument::query()->whereIn('type', ['FV', 'NC', 'ND'])->with('contact:id,name,identification')
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->input('identificacion'), fn ($q, $id) => $q->whereHas('contact', fn ($c) => $c->where('identification', $id)))
            ->orderByDesc('date')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('por_pagina', 50))));

        return $this->paged($page, fn (SalesDocument $d) => $this->invoice($d));
    }

    public function showInvoice(int $id): JsonResponse
    {
        $doc = SalesDocument::query()->with(['contact:id,name,identification', 'items'])->findOrFail($id);

        return response()->json($this->invoice($doc) + ['items' => $doc->items->sortBy('position')->values()->map(fn ($i) => [
            'codigo' => $i->code, 'descripcion' => $i->description, 'cantidad' => (float) $i->quantity, 'precio' => (float) $i->price,
            'descuento' => (float) $i->discount, 'iva' => (float) $i->tax_percentage, 'total' => (float) $i->total,
        ])]);
    }

    private function product(Product $p): array
    {
        return [
            'id' => $p->id, 'codigo' => $p->code, 'nombre' => $p->name, 'tipo' => $p->type, 'referencia' => $p->reference, 'codigo_barras' => $p->barcode,
            'unidad' => $p->unit_label, 'inventariable' => (bool) $p->stock_control, 'existencias' => (float) $p->stock, 'iva_incluido' => (bool) $p->tax_included,
            'precios' => $p->prices->map(fn ($x) => ['lista_precio_id' => $x->price_list_id, 'valor' => (float) $x->value])->values(),
            'impuestos' => $p->taxes->map(fn ($t) => ['nombre' => $t->name, 'porcentaje' => (float) $t->percentage])->values(), 'activo' => (bool) $p->active,
        ];
    }

    private function invoice(SalesDocument $d): array
    {
        return [
            'id' => $d->id, 'tipo' => $d->type, 'numero' => $d->name, 'fecha' => $d->date?->toDateString(), 'vencimiento' => $d->due_date?->toDateString(),
            'cliente' => ['identificacion' => $d->contact?->identification, 'nombre' => $d->contact?->name],
            'subtotal' => (float) $d->subtotal, 'descuento' => (float) $d->discount, 'impuestos' => (float) $d->tax_total, 'retenciones' => (float) $d->withholding_total,
            'total' => (float) $d->total, 'saldo' => (float) $d->balance, 'cufe' => $d->cufe, 'estado_dian' => $d->dian_status, 'anulada' => (bool) $d->annulled_at,
        ];
    }

    private function paged($page, callable $map): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'pagina' => $page->currentPage(), 'por_pagina' => $page->perPage(), 'total' => $page->total(), 'paginas' => $page->lastPage(),
        ]);
    }
}
