<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** CRUD generico para los catalogos definidos en config/catalogs.php */
class CatalogController extends Controller
{
    public function index(Request $request, string $catalog): Response
    {
        $def = $this->definition($catalog);
        /** @var class-string<Model> $model */
        $model = $def['model'];

        $query = $model::query()->with($def['with'] ?? [])->where($def['scope'] ?? []);
        foreach ($def['order'] as $col) {
            $query->orderBy($col);
        }
        if ($term = $request->input('q')) {
            $query->where(fn ($w) => collect($def['search'])->each(fn ($c) => $w->orWhere($c, 'like', "%$term%")));
        }
        if (isset($def['filter']) && $request->filled('f')) {
            $query->where($def['filter']['key'], $request->input('f'));
        }
        if ($request->input('estado') === 'activos') {
            $query->where('active', true);
        } elseif ($request->input('estado') === 'inactivos') {
            $query->where('active', false);
        }

        $rows = $query->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(fn (Model $m) => ['id' => $m->getKey(), ...collect($def['columns'])
                ->mapWithKeys(fn ($c) => [$c['key'] => data_get($m->toArray(), $c['key'])])->all()]);

        return Inertia::render('Settings/Catalog/Index', [
            'catalog' => $catalog,
            'title' => $def['title'],
            'singular' => $def['singular'],
            'columns' => $def['columns'],
            'filter' => $def['filter'] ?? null,
            'rows' => $rows,
            'filters' => $request->only('q', 'f', 'estado'),
        ]);
    }

    public function create(string $catalog): Response
    {
        $def = $this->definition($catalog);

        return $this->form($catalog, $def, null);
    }

    public function store(Request $request, string $catalog): RedirectResponse
    {
        $def = $this->definition($catalog);
        $data = $this->validated($request, $def);
        $this->checkUnique($def, $data);
        $def['model']::create([...$data, ...($def['scope'] ?? [])]);

        return redirect("/configuracion/$catalog")->with('success', ucfirst($def['singular']).' creado correctamente.');
    }

    public function edit(string $catalog, int $id): Response
    {
        $def = $this->definition($catalog);
        $record = $def['model']::where($def['scope'] ?? [])->findOrFail($id);

        return $this->form($catalog, $def, $record);
    }

    public function update(Request $request, string $catalog, int $id): RedirectResponse
    {
        $def = $this->definition($catalog);
        $record = $def['model']::where($def['scope'] ?? [])->findOrFail($id);
        $data = $this->validated($request, $def, $record);
        $this->checkUnique($def, $data, $record);
        $record->update($data);

        return redirect("/configuracion/$catalog")->with('success', ucfirst($def['singular']).' actualizado correctamente.');
    }

    private function form(string $catalog, array $def, ?Model $record): Response
    {
        $values = [];
        $accounts = [];
        foreach ($def['fields'] as $i => $f) {
            if (isset($f['options_from'])) {
                [$model, $label] = $f['options_from'];
                $def['fields'][$i]['options'] = $model::orderBy($label)->pluck($label, 'id')->all();
            }
            $value = $record ? $record->getAttribute($f['key']) : ($f['default'] ?? ($f['type'] === 'checkbox' ? false : ($f['type'] === 'multiselect' ? [] : null)));
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if (($f['secret'] ?? false) && $record) {
                $value = '';
            }
            $values[$f['key']] = $value;
            if ($f['type'] === 'account' && $value) {
                $accounts[$value] = Account::find($value)?->label();
            }
        }

        return Inertia::render('Settings/Catalog/Form', [
            'catalog' => $catalog,
            'title' => ($record ? 'Editar ' : 'Crear ').$def['singular'],
            'listTitle' => $def['title'],
            'fields' => $def['fields'],
            'values' => $values,
            'accountLabels' => $accounts,
            'recordId' => $record?->getKey(),
        ]);
    }

    private function validated(Request $request, array $def, ?Model $record = null): array
    {
        $labels = collect($def['fields'])->pluck('label', 'key')->map(fn ($l) => mb_strtolower($l))->all();
        $data = $request->validate($def['rules'], [], $labels);

        foreach ($def['fields'] as $f) {
            if ($f['type'] === 'checkbox') {
                $data[$f['key']] = (bool) ($data[$f['key']] ?? false);
            }
            if (($f['secret'] ?? false) && $record && empty($data[$f['key']])) {
                unset($data[$f['key']]);
            }
        }

        return Arr::only($data, array_column($def['fields'], 'key'));
    }

    private function checkUnique(array $def, array $data, ?Model $record = null): void
    {
        if (empty($def['unique'])) {
            return;
        }
        $q = $def['model']::query()->where($def['scope'] ?? []);
        foreach ($def['unique'] as $col) {
            $q->where($col, $data[$col] ?? null);
        }
        if ($record) {
            $q->whereKeyNot($record->getKey());
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([$def['unique'][count($def['unique']) - 1] => 'Ya existe un registro con este código.']);
        }
    }

    private function definition(string $catalog): array
    {
        $def = config("catalogs.$catalog");
        abort_if($def === null, 404);

        return $def;
    }
}
