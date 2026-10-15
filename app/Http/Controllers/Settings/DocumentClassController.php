<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DocumentType;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Services\Documents\DocumentLocator;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Configuracion > Documentos: un listado y un formulario propio por cada clase de comprobante (config/document_classes.php). */
class DocumentClassController extends Controller
{
    private const DEFAULTS = [
        'active' => true, 'automatic_number' => true, 'consecutive' => 1, 'decimals' => true, 'discount_type' => 'Percentage',
        'electronic_type' => 'NoElectronic', 'print_format' => 'Básico', 'show_values' => true, 'print_columns' => ['Nombre', 'Cantidad', 'Valor'],
        'phones' => [], 'charges' => [],
    ];

    public function index(Request $request, string $class)
    {
        $def = $this->definition($class);
        $types = DocumentType::query()->where('type', $def['type'])->orderByRaw('code + 0')->orderBy('code')->get();
        $rows = $types->map(fn (DocumentType $t) => $this->row($t, $def));

        if (in_array($request->input('formato'), ['xlsx', 'pdf'], true)) {
            return \App\Support\Download::array("tipos_comprobante_{$class}.xlsx", array_column($def['columns'], 'label'),
                $rows->map(fn ($r) => array_map(fn ($c) => $r[$c['key']], $def['columns']))->all(), $def['list_title']);
        }

        return Inertia::render('Settings/DocumentClasses/Index', [
            'slug' => $class, 'title' => $def['list_title'], 'columns' => $def['columns'], 'rows' => $rows,
            'export' => $def['export'] ?? false, 'style' => $def['style'] ?? 'grid',
        ]);
    }

    public function create(Request $request, string $class): Response
    {
        $def = $this->definition($class);
        $source = $request->filled('duplicar') ? DocumentType::query()->where('type', $def['type'])->find($request->integer('duplicar')) : null;
        $values = $source ? $this->values($source, $def) : $this->defaults($def);
        if ($source) {
            $values['code'] = (string) ((int) DocumentType::query()->where('type', $def['type'])->max(DB::raw('code + 0')) + 1);
            $values['resolution_number'] = null;
        }

        return $this->form($class, $def, null, $values);
    }

    public function store(Request $request, string $class): RedirectResponse
    {
        $def = $this->definition($class);
        $data = $this->validated($request, $def);
        DocumentType::create([...$data, 'company_id' => CurrentCompany::id(), 'type' => $def['type']]);

        return redirect("/configuracion/documentos/$class")->with('success', 'Tipo de comprobante creado correctamente.');
    }

    public function edit(string $class, int $id): Response
    {
        $def = $this->definition($class);
        $type = DocumentType::query()->where('type', $def['type'])->findOrFail($id);

        return $this->form($class, $def, $type, $this->values($type, $def));
    }

    public function update(Request $request, string $class, int $id): RedirectResponse
    {
        $def = $this->definition($class);
        $type = DocumentType::query()->where('type', $def['type'])->findOrFail($id);
        $type->update($this->validated($request, $def, $type));

        return redirect("/configuracion/documentos/$class")->with('success', 'Tipo de comprobante actualizado correctamente.');
    }

    public function destroy(string $class, int $id): RedirectResponse
    {
        $def = $this->definition($class);
        abort_if(($def['delete'] ?? true) === false, 404);
        $type = DocumentType::query()->where('type', $def['type'])->findOrFail($id);
        if ($this->usedBy($type)) {
            throw ValidationException::withMessages(['code' => 'No se puede borrar: el tipo de comprobante ya tiene documentos elaborados. Puedes desactivarlo.']);
        }
        $type->delete();

        return redirect("/configuracion/documentos/$class")->with('success', 'Tipo de comprobante borrado.');
    }

    /** "Vista previa" / "Ver formato": abre la impresion del ultimo documento elaborado con este tipo. */
    public function preview(string $class, int $id): RedirectResponse
    {
        $def = $this->definition($class);
        $type = DocumentType::query()->where('type', $def['type'])->findOrFail($id);
        $doc = $this->usedBy($type);
        if (! $doc) {
            return back()->with('error', 'Aún no hay documentos elaborados con este tipo de comprobante para ver el formato.');
        }

        return redirect('/documentos/'.DocumentLocator::kind($doc).'/'.$doc->id.'/imprimir');
    }

    private function usedBy(DocumentType $type): ?\Illuminate\Database\Eloquent\Model
    {
        foreach ([SalesDocument::class, PurchaseDocument::class, Receipt::class, JournalEntry::class] as $model) {
            if ($doc = $model::query()->where('document_type_id', $type->id)->latest('id')->first()) {
                return $doc;
            }
        }

        return null;
    }

    private function row(DocumentType $t, array $def): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'title' => $t->title ?: $t->name, 'active' => $t->active,
            'kind' => $def['type'] === 'FV' ? ($t->isElectronic() ? 'Factura' : 'Documento de ingreso') : ($t->isElectronic() ? 'Electrónico' : 'No electrónico'),
            'usage' => 'Nube', 'resolution_number' => $t->resolution_number, 'numbering_status' => $this->numberingStatus($t),
        ];
    }

    private function numberingStatus(DocumentType $t): string
    {
        if (! $t->resolution_number || ! $t->valid_to) {
            return $t->isElectronic() ? 'Sin resolución' : '';
        }
        if ($t->valid_to->isPast() || ($t->range_to && $t->consecutive > $t->range_to)) {
            return 'Vencida';
        }

        return $t->valid_to->diffInDays(now(), true) <= 30 || ($t->range_to && $t->range_to - $t->consecutive < 100) ? 'Próxima a vencer' : 'Vigente';
    }

    private function form(string $class, array $def, ?DocumentType $type, array $values): Response
    {
        $accounts = Account::query()->whereIn('id', array_filter([$values['discount_account_id'] ?? null, $values['gift_account_id'] ?? null, $values['advance_account_id'] ?? null]))
            ->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()]);
        $label = ($def['label'] ?? $def['type']).($type ? '-'.$type->code : '');

        return Inertia::render('Settings/DocumentClasses/Form', [
            'slug' => $class,
            'title' => $type ? trim($def['edit_title'].(($def['style'] ?? null) === 'simple' || $def['type'] === 'FV' ? '' : ' '.$label)) : $def['new_title'],
            'listTitle' => $def['list_title'],
            'sections' => $def['sections'],
            'style' => $def['style'] ?? 'classic',
            'buttons' => $def['buttons'] ?? 'save-first',
            'canDelete' => $type && ($def['delete'] ?? true),
            'deleteLabel' => $def['delete_label'] ?? 'Borrar',
            'canDuplicate' => $type && ($def['duplicate'] ?? false),
            'values' => $values,
            'accountLabels' => $accounts,
            'recordId' => $type?->id,
            'resolution' => $type && $def['type'] === 'FV' && $type->resolution_number ? [
                'number' => $type->resolution_number, 'from' => $type->valid_from?->format('Y-m-d'), 'to' => $type->valid_to?->format('Y-m-d'),
                'months' => $type->valid_from && $type->valid_to ? (int) round($type->valid_from->diffInMonths($type->valid_to)) : null,
                'prefix' => $type->prefix, 'range_from' => $type->range_from, 'range_to' => $type->range_to, 'status' => $this->numberingStatus($type),
            ] : null,
        ]);
    }

    private function defaults(array $def): array
    {
        $values = [...self::DEFAULTS, ...($def['defaults'] ?? [])];
        foreach ($this->fields($def) as $f) {
            $values[$f['key']] ??= in_array($f['type'], ['checkbox', 'yesno'], true) ? false : null;
        }
        $values['code'] = (string) ((int) DocumentType::query()->where('type', $def['type'])->max(DB::raw('code + 0')) + 1);
        $values['electronic'] = ($values['electronic_type'] ?? 'NoElectronic') !== 'NoElectronic';
        $values['commercial_sector'] ??= '';

        return $this->normalize($values);
    }

    private function values(DocumentType $t, array $def): array
    {
        $values = ['electronic_type' => $t->electronic_type, 'electronic' => $t->isElectronic()];
        foreach ($this->fields($def) as $f) {
            if (($f['virtual'] ?? false) || $f['key'] === 'electronic') {
                continue;
            }
            $v = $t->getAttribute($f['key']);
            $values[$f['key']] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        $values['print_columns'] = $t->print_columns ?: self::DEFAULTS['print_columns'];
        $values['phones'] = $t->phones ?: [];
        $values['charges'] = $t->charges ?: [];
        $values['title'] ??= $t->name;
        $values['commercial_sector'] ??= '';

        return $this->normalize($values);
    }

    private function normalize(array $values): array
    {
        if (array_key_exists('electronic', $values)) {
            $values['electronic'] = $values['electronic'] ? 'si' : 'no';
        }

        return $values;
    }

    /** @return list<array> campos editables (sin encabezados) de todas las secciones y pestañas */
    private function fields(array $def): array
    {
        $fields = [];
        foreach ($def['sections'] as $s) {
            foreach ([$s['fields'] ?? [], ...array_column($s['tabs'] ?? [], 'fields')] as $list) {
                foreach ($list as $f) {
                    if (isset($f['key']) && $f['type'] !== 'resolution') {
                        $fields[$f['key']] = $f;
                    }
                }
            }
        }

        return array_values($fields);
    }

    private function validated(Request $request, array $def, ?DocumentType $type = null): array
    {
        $fields = $this->fields($def);
        $rules = [];
        $labels = [];
        foreach ($fields as $f) {
            $k = $f['key'];
            $labels[$k] = mb_strtolower($f['label']);
            $req = ($f['required'] ?? false) ? 'required' : 'nullable';
            $rules[$k] = $k === 'electronic' ? ['nullable', Rule::in(['si', 'no'])] : match ($f['type']) {
                'checkbox', 'switch', 'yesno' => ['nullable', 'boolean'],
                'number' => [$req, 'integer', 'min:'.($k === 'consecutive' ? 1 : 0)],
                'date' => [$req, 'date'],
                'account' => [$req, 'integer', Rule::exists('accounts', 'id')->where('company_id', CurrentCompany::id())],
                'phones', 'columns', 'charges' => ['nullable', 'array'],
                'textarea' => [$req, 'string', 'max:'.($f['max'] ?? 2000)],
                default => [$req, 'string', 'max:255'],
            };
        }
        $rules['code'] = ['required', 'string', 'max:10', Rule::unique('document_types', 'code')
            ->where('company_id', CurrentCompany::id())->where('type', $def['type'])->ignore($type?->id)];
        $rules['phones.*.indicative'] = ['nullable', 'string', 'max:6'];
        $rules['phones.*.number'] = ['nullable', 'string', 'max:20'];
        $rules['phones.*.extension'] = ['nullable', 'string', 'max:10'];
        $rules['charges.*.name'] = ['required', 'string', 'max:80'];
        $rules['charges.*.kind'] = ['required', Rule::in(['Cargo', 'Descuento'])];
        $rules['charges.*.rate'] = ['nullable', 'numeric', 'min:0', 'max:100'];
        $rules['charges.*.account_id'] = ['nullable', 'integer'];
        $rules['charges.*.active'] = ['nullable', 'boolean'];
        $rules['print_columns.*'] = ['string', 'max:40'];
        $data = $request->validate($rules, ['code.unique' => 'Ya existe un tipo de comprobante con este código.'], $labels + ['code' => 'código del comprobante']);

        $out = [];
        foreach ($fields as $f) {
            if ($f['virtual'] ?? false) {
                continue;
            }
            $k = $f['key'];
            $v = $data[$k] ?? null;
            if ($k === 'electronic') {
                if (collect($fields)->contains('key', 'electronic_type')) {
                    continue;
                }
                $out['electronic_type'] = in_array($v, ['si', '1', 1, true], true) ? $f['electronic'] : 'NoElectronic';

                continue;
            }
            if ($v === null && in_array($k, ['discount_type', 'print_format', 'electronic_type'], true)) {
                continue;
            }
            $out[$k] = in_array($f['type'], ['checkbox', 'switch', 'yesno'], true) ? (bool) $v : $v;
        }
        if (isset($out['phones'])) {
            $out['phones'] = array_values(array_filter($out['phones'], fn ($p) => trim((string) ($p['number'] ?? '')) !== ''));
        }
        if (isset($out['commercial_sector']) && $out['commercial_sector'] === '') {
            $out['commercial_sector'] = null;
        }
        if (! $type) {
            foreach ($def['defaults'] ?? [] as $k => $v) {
                $out[$k] ??= $v;
            }
        }

        return $out;
    }

    private function definition(string $class): array
    {
        return config("document_classes.$class") ?? abort(404);
    }
}
