<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DocumentType;
use App\Models\JournalEntry;
use App\Models\SalesDocument;
use App\Services\Accounting\ExogenousReport;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\Numbering;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Contabilidad > Mas procesos. */
class ProcessesController extends Controller
{
    private const BACK = '/contabilidad/mas-procesos';

    public function __construct(private readonly Numbering $numbering, private readonly JournalRegistrar $journals) {}

    /* ---------------------------------------------------------------- Cierre de año */

    public function yearCloses(Request $request): Response
    {
        $rows = JournalEntry::query()->where('source_type', 'year_close')->where('status', '!=', 'annulled')->orderByDesc('date')->paginate(20)->withQueryString();
        $users = \App\Models\User::query()->whereIn('id', $rows->pluck('created_by')->filter())->pluck('name', 'id');
        $rows->through(fn (JournalEntry $e) => [
            $e->date->format('Y'), $users[$e->created_by] ?? '', $e->created_at?->format('d/m/Y H:i') ?? '',
            ['text' => $e->name, 'href' => "/documentos/comprobantes/{$e->id}"],
            ['actions' => [['text' => 'Borrar', 'danger' => true, 'post' => "/contabilidad/cierre-anio/{$e->id}/borrar",
                'confirm' => 'Se borrarán todos los comprobantes contables generados desde el proceso de cierre anual del año '.$e->date->format('Y').'. ¿Continuar?']]],
        ]);

        return $this->list('Cierre de año', '/contabilidad/cierre-anio', $rows, ['Año fiscal', 'Usuario que hizo el cierre', 'Fecha de cierre', 'Comprobante', 'Acciones'], [
            'buttons' => [['label' => 'Cierre de año', 'href' => '/contabilidad/cierre-anio/crear']],
            'empty' => ['No se encontraron resultados para tu búsqueda', 'Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo.'],
        ], []);
    }

    public function yearCloseDelete(JournalEntry $entry, DocumentReverser $reverser): RedirectResponse
    {
        abort_unless($entry->source_type === 'year_close', 404);
        $reverser->annul($entry);

        return redirect('/contabilidad/cierre-anio')->with('success', "Se borró el cierre de año {$entry->date->format('Y')} ({$entry->name}).");
    }

    public function yearClose(Request $request): Response
    {
        $year = (int) $request->input('anio', now()->year - 1);
        [$rows, $profit] = $this->closingBalances($year);
        $existing = $this->closingEntry($year);

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Cierre de año', 'back' => '/contabilidad/cierre-anio', 'action' => '/contabilidad/cierre-anio',
                'info' => "Cancela los saldos de las cuentas de resultado (clases 4, 5, 6 y 7) por tercero al 31 de diciembre y lleva la diferencia a la cuenta de utilidad o pérdida del ejercicio con el comprobante CC-998 Cierre año."
                    .($existing ? "\n\nYa existe el cierre {$existing->name} para {$year}. Para generarlo de nuevo anúlalo primero." : ''),
                'fields' => [
                    ['name' => 'anio', 'label' => 'Año a cerrar', 'type' => 'year', 'required' => true],
                    ['name' => 'profit_account_id', 'label' => 'Cuenta utilidad del ejercicio', 'type' => 'account', 'required' => true],
                    ['name' => 'loss_account_id', 'label' => 'Cuenta pérdida del ejercicio', 'type' => 'account', 'required' => true],
                    ['name' => 'contact_id', 'label' => 'Tercero de la cuenta de resultado', 'type' => 'lookup', 'url' => '/api/buscar/terceros', 'required' => true],
                ],
                'buttons' => [
                    ['label' => 'Consultar', 'style' => 'outline', 'data' => ['preview' => 1]],
                    ['label' => 'Generar cierre', 'confirm' => "Se contabilizará el cierre del año. ¿Continuar?"],
                ],
            ],
            'initial' => $this->closeDefaults($request) + ['anio' => $year],
            'preview' => [
                'title' => "Saldos a cerrar al 31/12/{$year}", 'text' => ($profit >= 0 ? 'Utilidad' : 'Pérdida').' del ejercicio: $ '.number_format(abs($profit), 2, ',', '.'),
                'columns' => ['Código', 'Cuenta', 'Saldo débito', 'Saldo crédito'], 'money' => [2, 3], 'rows' => $rows,
                'totals' => ['Total', '', array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3))],
            ],
        ]);
    }

    public function yearCloseStore(Request $request): RedirectResponse
    {
        if ($request->boolean('preview')) {
            return redirect('/contabilidad/cierre-anio/crear?'.http_build_query($request->only('anio', 'profit_account_id', 'loss_account_id', 'contact_id')));
        }
        $data = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:'.now()->year],
            'profit_account_id' => ['required', 'integer', 'exists:accounts,id'], 'loss_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
        ], ['profit_account_id.required' => 'Selecciona la cuenta de utilidad', 'loss_account_id.required' => 'Selecciona la cuenta de pérdida', 'contact_id.required' => 'Selecciona el tercero']);
        $year = (int) $data['anio'];
        if ($e = $this->closingEntry($year)) {
            throw ValidationException::withMessages(['anio' => "El año {$year} ya tiene el cierre {$e->name}. Anúlalo para generarlo de nuevo."]);
        }
        $lines = [];
        $contacts = [];
        $total = 0.0;
        foreach ($this->groupedBalances(['4', '5', '6', '7'], "$year-12-31") as $b) {
            $bal = round((float) $b->bal, 2);
            $contacts[$b->contact_id] ??= $b->contact_id ? Contact::query()->find($b->contact_id) : null;
            $lines[] = ['account_id' => (int) $b->account_id, 'contact' => $contacts[$b->contact_id], 'description' => "Cierre año $year", 'debit' => $bal < 0 ? -$bal : 0, 'credit' => $bal > 0 ? $bal : 0];
            $total += $bal;
        }
        if (! $lines) {
            throw ValidationException::withMessages(['anio' => 'No hay saldos en cuentas de resultado para cerrar.']);
        }
        $total = round($total, 2);
        $contact = Contact::query()->find($data['contact_id']);
        if ($total != 0) {
            $lines[] = $total < 0
                ? ['account_id' => (int) $data['profit_account_id'], 'contact' => $contact, 'description' => "Utilidad del ejercicio $year", 'debit' => 0, 'credit' => -$total]
                : ['account_id' => (int) $data['loss_account_id'], 'contact' => $contact, 'description' => "Pérdida del ejercicio $year", 'debit' => $total, 'credit' => 0];
        }
        $entry = $this->journals->store($this->numbering->journalType('998', 'Cierre año'), ['date' => "$year-12-31", 'observations' => "Cierre año $year"], $lines, 'year_close');

        return redirect("/documentos/comprobantes/{$entry->id}")->with('success', "Se contabilizó el cierre del año {$year} en {$entry->name}.");
    }

    private function closingBalances(int $year): array
    {
        $rows = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('date', '<=', "$year-12-31")
            ->where(fn ($q) => $q->where('account_code', 'like', '4%')->orWhere('account_code', 'like', '5%')->orWhere('account_code', 'like', '6%')->orWhere('account_code', 'like', '7%'))
            ->groupBy('account_code')->selectRaw('account_code, sum(debit)-sum(credit) bal')->havingRaw('abs(sum(debit)-sum(credit)) >= 0.01')->orderBy('account_code')->get();
        $names = Account::query()->whereIn('code', $rows->pluck('account_code'))->pluck('name', 'code');

        return [$rows->map(fn ($r) => [$r->account_code, $names[$r->account_code] ?? '', max((float) $r->bal, 0), max(-(float) $r->bal, 0)])->all(), -round($rows->sum('bal'), 2)];
    }

    private function closingEntry(int $year): ?JournalEntry
    {
        return JournalEntry::query()->where('source_type', 'year_close')->where('status', '!=', 'annulled')->whereDate('date', "$year-12-31")->first();
    }

    private function closeDefaults(Request $request): array
    {
        $acc = fn ($id, $code) => ($a = $id ? Account::query()->find($id) : Account::query()->where('code', $code)->first()) ? [$a->id, "{$a->code} - {$a->name}"] : [null, ''];
        [$p, $pl] = $acc($request->input('profit_account_id'), '360505');
        [$l, $ll] = $acc($request->input('loss_account_id'), '361005');
        $company = Company::query()->find(CurrentCompany::id());
        $contact = $request->input('contact_id') ? Contact::query()->find($request->input('contact_id')) : Contact::query()->where('identification', $company?->nit)->first();

        return ['profit_account_id' => $p, 'profit_account_id_label' => $pl, 'loss_account_id' => $l, 'loss_account_id_label' => $ll,
            'contact_id' => $contact?->id, 'contact_id_label' => $contact ? "{$contact->identification} - {$contact->name}" : ''];
    }

    /** Saldos por cuenta y tercero (debito - credito) hasta una fecha. */
    private function groupedBalances(array $prefixes, string $until, ?string $codeFrom = null, ?string $codeTo = null)
    {
        return DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('date', '<=', $until)
            ->when($prefixes, fn ($q) => $q->where(fn ($w) => collect($prefixes)->each(fn ($p) => $w->orWhere('account_code', 'like', "$p%"))))
            ->when($codeFrom, fn ($q) => $q->where('account_code', '>=', $codeFrom))
            ->when($codeTo, fn ($q) => $q->whereRaw('left(account_code, ?) <= ?', [strlen($codeTo), $codeTo]))
            ->whereNotNull('account_id')
            ->groupBy('account_id', 'account_code', 'contact_id')->selectRaw('account_id, account_code, contact_id, sum(debit)-sum(credit) bal')
            ->havingRaw('abs(sum(debit)-sum(credit)) >= 0.01')->orderBy('account_code')->get();
    }

    /* ---------------------------------------------------------------- Cierre cuentas de impuestos y otras por tercero */

    /** Siigo: por cada cuenta, los saldos de todos los terceros al 31/12 se trasladan al tercero indicado en la misma cuenta. */
    public function taxClose(Request $request): Response
    {
        $year = (int) $request->input('anio', now()->year - 1);
        $pairs = $this->closePairs($request->input('cuentas', []));
        $rows = [];
        foreach ($pairs as $p) {
            foreach ($this->accountBalances($p['account'], "$year-12-31") as $b) {
                $rows[] = [$p['account']->code, $p['account']->name, $b['contact'], $p['contact']->name, max($b['bal'], 0), max(-$b['bal'], 0)];
            }
        }

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Cierre cuentas de impuestos y otras cuentas por clientes, proveedores u otros', 'back' => self::BACK, 'action' => '/contabilidad/cierre-impuestos',
                'subtitle' => 'Selecciona el año para el cierre anual.',
                'info' => "Relaciona la cuenta contable a un cliente, proveedor u otro para realizar el cierre: los saldos de la cuenta con todos los terceros al 31 de diciembre se trasladan a ese tercero en la misma cuenta (por ejemplo, el IVA y las retenciones a la DIAN). Se contabiliza con el comprobante CC-1 Ajustes contables.\nEste proceso puede tomar unos minutos.",
                'fields' => [['name' => 'anio', 'label' => 'Año de cierre', 'type' => 'year', 'required' => true]],
                'grid' => ['name' => 'cuentas', 'label' => 'Cuentas a cerrar', 'add' => 'Agregar cuenta', 'columns' => [
                    ['name' => 'account_id', 'label' => 'Cuenta contable', 'type' => 'account'],
                    ['name' => 'contact_id', 'label' => 'Cliente, proveedor u otro', 'type' => 'lookup', 'url' => '/api/buscar/terceros'],
                ]],
                'buttons' => [['label' => 'Consultar', 'style' => 'outline', 'data' => ['preview' => 1]], ['label' => 'Contabilizar', 'confirm' => 'Se trasladarán los saldos de las cuentas al tercero indicado. ¿Continuar?']],
            ],
            'initial' => ['anio' => $year, 'cuentas' => collect($pairs)->map(fn ($p) => [
                'account_id' => $p['account']->id, 'account_id_label' => "{$p['account']->code} - {$p['account']->name}",
                'contact_id' => $p['contact']->id, 'contact_id_label' => "{$p['contact']->identification} - {$p['contact']->name}",
            ])->all()],
            'preview' => $pairs ? ['title' => "Saldos a trasladar al 31/12/{$year}", 'columns' => ['Código', 'Cuenta', 'Tercero actual', 'Tercero destino', 'Saldo débito', 'Saldo crédito'], 'money' => [4, 5], 'rows' => $rows,
                'totals' => ['Total', '', '', '', array_sum(array_column($rows, 4)), array_sum(array_column($rows, 5))]] : null,
        ]);
    }

    public function taxCloseStore(Request $request): RedirectResponse
    {
        $request->validate(['anio' => ['required', 'integer', 'min:2000', 'max:'.now()->year], 'cuentas' => ['required', 'array', 'min:1']],
            ['cuentas.required' => 'Agrega al menos una cuenta contable con su tercero', 'cuentas.min' => 'Agrega al menos una cuenta contable con su tercero']);
        if ($request->boolean('preview')) {
            return redirect('/contabilidad/cierre-impuestos?'.http_build_query($request->only('anio', 'cuentas')));
        }
        $year = (int) $request->input('anio');
        $pairs = $this->closePairs($request->input('cuentas'));
        if (! $pairs) {
            throw ValidationException::withMessages(['cuentas' => 'Agrega al menos una cuenta contable con su tercero']);
        }
        $lines = [];
        foreach ($pairs as $p) {
            $total = 0.0;
            foreach ($this->accountBalances($p['account'], "$year-12-31") as $b) {
                if ($b['contact_id'] === $p['contact']->id) {
                    continue;
                }
                $lines[] = ['account_id' => $p['account']->id, 'contact' => $b['contact_id'] ? Contact::query()->find($b['contact_id']) : null, 'description' => "Cierre cuenta $year", 'debit' => $b['bal'] < 0 ? -$b['bal'] : 0, 'credit' => $b['bal'] > 0 ? $b['bal'] : 0];
                $total += $b['bal'];
            }
            $total = round($total, 2);
            if ($total != 0) {
                $lines[] = ['account_id' => $p['account']->id, 'contact' => $p['contact'], 'description' => "Cierre cuenta $year", 'debit' => $total > 0 ? $total : 0, 'credit' => $total < 0 ? -$total : 0];
            }
        }
        if (! $lines) {
            throw ValidationException::withMessages(['cuentas' => 'Las cuentas no tienen saldos con otros terceros para cerrar.']);
        }
        $entry = $this->journals->store($this->numbering->journalType('1', 'Ajustes contables'), ['date' => "$year-12-31", 'observations' => "Cierre cuentas de impuestos y otras por tercero $year"], $lines, 'tax_close');

        return redirect("/documentos/comprobantes/{$entry->id}")->with('success', "Se contabilizó el cierre de cuentas en {$entry->name}.");
    }

    /** @return list<array{account: Account, contact: Contact}> */
    private function closePairs(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $i => $r) {
            if (empty($r['account_id']) && empty($r['contact_id'])) {
                continue;
            }
            $account = Account::query()->find($r['account_id'] ?? 0);
            $contact = Contact::query()->find($r['contact_id'] ?? 0);
            if (! $account || ! $contact) {
                throw ValidationException::withMessages(['cuentas' => 'Fila '.($i + 1).': selecciona la cuenta contable y el tercero']);
            }
            if (! $account->transactional) {
                throw ValidationException::withMessages(['cuentas' => "Fila ".($i + 1).": la cuenta {$account->code} no es de movimiento"]);
            }
            $out[] = compact('account', 'contact');
        }

        return $out;
    }

    /** @return list<array{contact_id: ?int, contact: string, bal: float}> */
    private function accountBalances(Account $account, string $until): array
    {
        $rows = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('date', '<=', $until)->where('account_code', $account->code)
            ->groupBy('contact_id')->selectRaw('contact_id, max(contact_name) name, max(identification) ident, sum(debit)-sum(credit) bal')
            ->havingRaw('abs(sum(debit)-sum(credit)) >= 0.01')->get();

        return $rows->map(fn ($r) => ['contact_id' => $r->contact_id ? (int) $r->contact_id : null, 'contact' => trim(($r->ident ?? '').' '.($r->name ?? '')), 'bal' => round((float) $r->bal, 2)])->all();
    }

    /* ---------------------------------------------------------------- Diferencia en cambio */

    private const CURRENCIES = ['USD' => 'Dólar estadounidense', 'EUR' => 'Euro', 'CLP' => 'Peso chileno', 'AED' => 'Dirham de los Emiratos Árabes Unidos', 'ANG' => 'Florín antillano neerlandés',
        'ARS' => 'Peso argentino', 'AUD' => 'Dólar australiano', 'BOB' => 'Boliviano', 'BRL' => 'Real brasileño', 'CAD' => 'Dólar canadiense', 'CHF' => 'Franco suizo', 'CNY' => 'Yuan chino',
        'CRC' => 'Colón costarricense', 'DKK' => 'Corona danesa', 'DOP' => 'Peso dominicano', 'GBP' => 'Libra esterlina', 'GTQ' => 'Quetzal guatemalteco', 'HKD' => 'Dólar de Hong Kong',
        'HNL' => 'Lempira hondureño', 'HUF' => 'Forint húngaro', 'JPY' => 'Yen japonés', 'MXN' => 'Peso mexicano', 'NZD' => 'Dólar neozelandés', 'PAB' => 'Balboa panameño', 'PEN' => 'Sol peruano',
        'PYG' => 'Guaraní paraguayo', 'SGD' => 'Dólar de Singapur', 'SVC' => 'Colón salvadoreño', 'UYU' => 'Peso uruguayo', 'ZAR' => 'Rand sudafricano'];

    public function exchange(Request $request): Response
    {
        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Diferencia en cambio', 'back' => self::BACK, 'action' => '/contabilidad/diferencia-cambio/revisar', 'method' => 'get', 'submit' => 'Siguiente',
                'steps' => ['Seleccione el periodo', 'Revisa las diferencias'], 'step' => 1,
                'fields' => $this->exchangeFields(),
            ],
            'initial' => $this->exchangeInitial($request),
        ]);
    }

    public function exchangeReview(Request $request): Response|RedirectResponse
    {
        $h = $this->exchangeHeader($request);
        $end = \Illuminate\Support\Carbon::create($h['anio'], $h['mes'], 1)->endOfMonth()->toDateString();
        $rate = (float) (($h['rate'] ?? null) ?: $this->trm($h['moneda'], $end));
        $customers = $this->accountResolver()->default('customers');
        $rows = SalesDocument::query()->with('contact:id,identification,name')->where('currency', $h['moneda'])->where('balance', '>', 0)->where('status', '!=', 'annulled')
            ->whereDate('date', '<=', $end)->orderBy('date')->get()
            ->filter(fn ($d) => (float) $d->exchange_rate > 0)
            ->map(fn ($d) => [
                'document' => $d->name, 'account_id' => $customers, 'account_id_label' => Account::query()->whereKey($customers)->value(DB::raw("concat(code, ' - ', name)")),
                'contact_id' => $d->contact_id, 'contact_id_label' => "{$d->contact?->identification} - {$d->contact?->name}",
                'foreign' => round((float) $d->balance / (float) $d->exchange_rate, 2), 'rate_from' => (float) $d->exchange_rate,
            ])->values()->all();

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Diferencia en cambio', 'back' => '/contabilidad/diferencia-cambio?'.http_build_query($request->except('rows')), 'action' => '/contabilidad/diferencia-cambio', 'submit' => 'Contabilizar',
                'steps' => ['Seleccione el periodo', 'Revisa las diferencias'], 'step' => 2,
                'info' => ($rows ? count($rows).' documentos con saldo en '.$h['moneda'].' al '.date('d/m/Y', strtotime($end)).'.' : 'No hay documentos con saldo en '.$h['moneda'].' al '.date('d/m/Y', strtotime($end)).'.')
                    .' Agrega las demás cuentas en moneda extranjera (bancos, obligaciones, proveedores) con su saldo en '.$h['moneda'].' y la tasa con que se registraron. La diferencia se calcula con la tasa de cierre y se lleva a la cuenta de ganancia o pérdida.',
                'fields' => [...$this->exchangeFields(), ['name' => 'rate', 'label' => 'Tasa de cambio de cierre', 'type' => 'money', 'required' => true, 'help' => $h['moneda'] === 'USD' ? 'TRM vigente al cierre del mes (Superintendencia Financiera)' : null]],
                'grid' => ['name' => 'rows', 'label' => 'Revisa las diferencias', 'add' => 'Agregar cuenta', 'columns' => [
                    ['name' => 'document', 'label' => 'Documento', 'type' => 'static'],
                    ['name' => 'account_id', 'label' => 'Cuenta contable', 'type' => 'account'],
                    ['name' => 'contact_id', 'label' => 'Tercero', 'type' => 'lookup', 'url' => '/api/buscar/terceros'],
                    ['name' => 'foreign', 'label' => 'Saldo en '.$h['moneda'], 'type' => 'money'],
                    ['name' => 'rate_from', 'label' => 'Tasa registrada', 'type' => 'money'],
                ]],
            ],
            'initial' => ['rate' => $rate ?: null, 'rows' => $rows] + $this->exchangeInitial($request),
        ]);
    }

    public function exchangeStore(Request $request): RedirectResponse
    {
        $h = $this->exchangeHeader($request);
        $request->validate(['rate' => ['required', 'numeric', 'gt:0']], ['rate.required' => 'Indica la tasa de cambio de cierre']);
        $rateTo = (float) $request->input('rate');
        $date = \Illuminate\Support\Carbon::create($h['anio'], $h['mes'], 1)->endOfMonth()->toDateString();
        $contact = Contact::query()->find($h['contact_id']);
        $lines = [];
        $gain = 0.0;
        foreach (array_values($request->input('rows', [])) as $i => $r) {
            if (empty($r['account_id'])) {
                continue;
            }
            $foreign = (float) ($r['foreign'] ?? 0);
            $rateFrom = (float) ($r['rate_from'] ?? 0);
            if ($foreign == 0 || $rateFrom <= 0) {
                throw ValidationException::withMessages(['rows' => 'Fila '.($i + 1).': indica el saldo en moneda extranjera y la tasa registrada']);
            }
            $diff = round($foreign * ($rateTo - $rateFrom), 2);
            if ($diff == 0) {
                continue;
            }
            $account = Account::query()->findOrFail($r['account_id']);
            $asset = in_array($account->code[0], ['1', '5', '6', '7'], true);
            $v = abs($diff);
            $up = $diff > 0;
            $lines[] = ['account_id' => $account->id, 'contact' => ! empty($r['contact_id']) ? Contact::query()->find($r['contact_id']) : $contact, 'description' => "Diferencia en cambio {$h['moneda']} ".($r['document'] ?? ''),
                'debit' => $asset === $up ? $v : 0, 'credit' => $asset === $up ? 0 : $v, 'cost_center_id' => $h['cost_center_id'] ?? null];
            $gain += $asset === $up ? $v : -$v;
        }
        if (! $lines) {
            throw ValidationException::withMessages(['rows' => 'No hay diferencias en cambio para contabilizar con la tasa de cierre.']);
        }
        $gain = round($gain, 2);
        if ($gain != 0) {
            $lines[] = ['account_id' => (int) ($gain > 0 ? $h['income_account_id'] : $h['expense_account_id']), 'contact' => $contact, 'description' => ($gain > 0 ? 'Ganancia' : 'Pérdida')." por diferencia en cambio {$h['moneda']}",
                'debit' => $gain > 0 ? 0 : -$gain, 'credit' => $gain > 0 ? $gain : 0, 'cost_center_id' => $h['cost_center_id'] ?? null];
        }
        $type = DocumentType::query()->where('type', 'CC')->find($h['document_type']) ?? $this->numbering->journalType('1', 'Ajustes contables');
        $entry = $this->journals->store($type, ['date' => $date, 'observations' => "Diferencia en cambio {$h['moneda']} ".sprintf('%02d/%d', $h['mes'], $h['anio'])." tasa de cierre $rateTo"], $lines, 'exchange_difference');

        return redirect("/documentos/comprobantes/{$entry->id}")->with('success', "Se contabilizó la diferencia en cambio en {$entry->name}.");
    }

    private function exchangeFields(): array
    {
        return [
            ['name' => 'anio', 'label' => 'Año de proceso', 'type' => 'year', 'required' => true],
            ['name' => 'mes', 'label' => 'Mes de proceso', 'type' => 'month', 'required' => true],
            ['name' => 'moneda', 'label' => 'Moneda', 'type' => 'select', 'required' => true, 'options' => collect(self::CURRENCIES)->map(fn ($n, $c) => [$c, "$c - $n"])->values()->all()],
            ['name' => 'document_type', 'label' => 'Comprobante', 'type' => 'select', 'required' => true,
                'options' => DocumentType::query()->where('type', 'CC')->orderBy('code')->get()->map(fn ($t) => [$t->id, "CC-{$t->code} - {$t->name}"])->all()],
            ['name' => 'cost_center_id', 'label' => 'Centro de costo', 'type' => 'select', 'options' => DB::table('cost_centers')->where('company_id', CurrentCompany::id())->where('active', true)->orderBy('code')->get()->map(fn ($c) => [$c->id, "{$c->code} - {$c->name}"])->all()],
            ['name' => 'contact_id', 'label' => 'NIT de la empresa', 'type' => 'lookup', 'url' => '/api/buscar/terceros', 'required' => true],
            ['name' => 'income_account_id', 'label' => 'Cuenta Contable Por Ganancia', 'type' => 'account', 'required' => true],
            ['name' => 'expense_account_id', 'label' => 'Cuenta Contable Por Perdida', 'type' => 'account', 'required' => true],
        ];
    }

    private function exchangeInitial(Request $request): array
    {
        $label = fn ($id) => $id ? Account::query()->whereKey($id)->value(DB::raw("concat(code, ' - ', name)")) : '';
        $income = $request->input('income_account_id') ?? Account::query()->where('code', 'like', '421020%')->where('transactional', true)->orderBy('code')->value('id');
        $expense = $request->input('expense_account_id') ?? Account::query()->where('code', 'like', '530525%')->where('transactional', true)->orderBy('code')->value('id');
        $company = Company::query()->find(CurrentCompany::id());
        $contact = $request->input('contact_id') ? Contact::query()->find($request->input('contact_id')) : Contact::query()->where('identification', $company?->nit)->first();

        return [
            'anio' => (int) $request->input('anio', now()->year), 'mes' => (int) $request->input('mes', now()->month), 'moneda' => $request->input('moneda'),
            'document_type' => $request->input('document_type') ? (int) $request->input('document_type') : DocumentType::query()->where('type', 'CC')->where('code', '1')->value('id'),
            'cost_center_id' => $request->input('cost_center_id') ? (int) $request->input('cost_center_id') : null,
            'contact_id' => $contact?->id, 'contact_id_label' => $contact ? "{$contact->identification} - {$contact->name}" : '',
            'income_account_id' => $income ? (int) $income : null, 'income_account_id_label' => $label($income),
            'expense_account_id' => $expense ? (int) $expense : null, 'expense_account_id_label' => $label($expense),
            'rate' => $request->input('rate'),
        ];
    }

    private function exchangeHeader(Request $request): array
    {
        return $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'], 'mes' => ['required', 'integer', 'between:1,12'], 'moneda' => ['required', 'in:'.implode(',', array_keys(self::CURRENCIES))],
            'document_type' => ['required', 'integer', 'exists:document_types,id'], 'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'], 'income_account_id' => ['required', 'integer', 'exists:accounts,id'], 'expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'rate' => ['nullable', 'numeric'],
        ], ['moneda.required' => 'Selecciona la moneda', 'contact_id.required' => 'Selecciona el NIT de la empresa', 'income_account_id.required' => 'Selecciona la cuenta por ganancia', 'expense_account_id.required' => 'Selecciona la cuenta por pérdida']);
    }

    /** TRM de datos abiertos (Superintendencia Financiera) vigente en la fecha; solo para USD. */
    private function trm(string $currency, string $date): ?float
    {
        if ($currency !== 'USD') {
            return null;
        }
        try {
            $r = \Illuminate\Support\Facades\Http::timeout(5)->get('https://www.datos.gov.co/resource/32sa-8pi3.json', [
                '$where' => "vigenciadesde <= '{$date}T00:00:00.000'", '$order' => 'vigenciadesde DESC', '$limit' => 1,
            ]);

            return (float) ($r->json('0.valor') ?? 0) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function accountResolver(): \App\Services\Accounting\AccountResolver
    {
        return app(\App\Services\Accounting\AccountResolver::class);
    }

    /* ---------------------------------------------------------------- Favoritos y borrado masivo */

    public function favorites(Request $request): Response
    {
        $firstContact = 'select c.id from journal_lines l join contacts c on c.id = l.contact_id where l.journal_entry_id = journal_entries.id order by l.id limit 1';
        $rows = JournalEntry::query()->where('favorite', true)
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('observations', 'like', "%$t%")))
            ->when($request->input('tercero'), fn ($q, $t) => $q->whereExists(fn ($e) => $e->from('journal_lines as l')->join('contacts as c', 'c.id', '=', 'l.contact_id')
                ->whereColumn('l.journal_entry_id', 'journal_entries.id')->where(fn ($w) => $w->where('c.name', 'like', "%$t%")->orWhere('c.identification', 'like', "$t%"))))
            ->selectRaw("journal_entries.*, ($firstContact) as first_contact_id")
            ->orderByDesc('date')->paginate(20)->withQueryString();
        $contacts = Contact::query()->whereIn('id', $rows->pluck('first_contact_id')->filter())->get(['id', 'identification', 'name'])->keyBy('id');
        $rows->through(fn (JournalEntry $e) => [
            'Comprobante contable', ['text' => $e->observations ?? '', 'truncate' => true], ['text' => $e->name, 'href' => "/documentos/comprobantes/{$e->id}"],
            $contacts[$e->first_contact_id]->identification ?? '', ['text' => $contacts[$e->first_contact_id]->name ?? '', 'truncate' => true],
            ['text' => number_format((float) $e->debit, 2, '.', ','), 'align' => 'right'],
            ['actions' => [
                ['text' => 'Copiar', 'href' => "/contabilidad/comprobantes/crear?copiar=comprobantes:{$e->id}"],
                ['text' => 'Quitar de favoritos', 'post' => "/documentos/comprobantes/{$e->id}/favorito"],
            ]],
        ]);

        return $this->list('Comprobantes favoritos', '/contabilidad/comprobantes-favoritos', $rows, ['Tipo de transacción', 'Descripción', 'Comprobante', 'Identificación', 'Tercero', 'Valor', 'Acción'], [
            'search' => 'Descripción o comprobante', 'search_label' => 'Descripción',
            'selects' => [['name' => 'tercero', 'label' => 'Tercero', 'options' => Contact::query()->whereIn('id', DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.favorite', true)->whereNotNull('journal_lines.contact_id')->distinct()->pluck('journal_lines.contact_id'))->orderBy('name')->pluck('name')->all()]],
            'info' => 'Marca un comprobante como favorito desde su detalle (botón "Marcar favorito") para copiarlo cuando lo necesites con fecha de hoy y un número nuevo.',
            'empty' => ['No se encontraron resultados para tu búsqueda', 'Abre un comprobante contable y usa "Marcar favorito"'],
        ], $request->only('q', 'tercero'));
    }

    /** Clases de comprobante del borrado masivo de Siigo: modelo y tipo de documento. */
    private const MASS_CLASSES = [
        'Factura de venta' => [SalesDocument::class, 'FV'], 'Recibo' => [\App\Models\Receipt::class, 'RC'], 'Nota crédito' => [SalesDocument::class, 'NC'],
        'Nota débito (Ventas)' => [SalesDocument::class, 'ND'], 'Compra' => [\App\Models\PurchaseDocument::class, 'FC'], 'Pagos' => [\App\Models\Receipt::class, 'RP'],
        'Nota débito' => [\App\Models\PurchaseDocument::class, 'ND'], 'Comprobante contable' => [JournalEntry::class, 'CC'],
    ];

    public function massDelete(Request $request): Response
    {
        $f = $request->only('comprobante', 'tipo', 'desde', 'hasta');
        $preview = null;
        if (! empty($f['comprobante']) && ! empty($f['tipo'])) {
            $rows = $this->massDocs($f)->map(fn ($d) => [$d->name, substr((string) $d->date, 0, 10), $this->contactName($d), (float) ($d->total ?? $d->debit ?? 0), $this->massBlocker($d) ?? 'Se borrará'])->all();
            $preview = ['title' => count($rows).' comprobantes en el rango', 'columns' => ['Comprobante', 'Fecha', 'Tercero', 'Valor', 'Resultado'], 'money' => [3], 'rows' => $rows];
        }
        $types = DocumentType::query()->whereIn('type', array_unique(array_column(self::MASS_CLASSES, 1)))->orderBy('type')->orderByRaw('cast(code as unsigned)')->get();

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Borrado masivo de comprobantes', 'back' => self::BACK, 'action' => '/contabilidad/borrado-masivo',
                'info' => "¡Importante!\n1. Con esta opción podrás borrar masivamente tus documentos, a excepción de los documentos enviados electrónicamente (factura de venta y nómina).\n2. Los comprobantes asociados a los procesos de costeo mensual, cierre cuentas de impuestos y otras cuentas por tercero y cierre de año, los debes eliminar de manera manual.\n3. Para conocer el reporte de auditoría de borrado de comprobantes consulta Configuración › Auditoría.\nLos comprobantes borrados quedan anulados: se reversa su contabilización y conservan el número.",
                'subtitle' => 'Selecciona el tipo de comprobante a borrar:',
                'fields' => [
                    ['name' => 'comprobante', 'label' => 'Comprobante', 'type' => 'select', 'required' => true, 'options' => array_keys(self::MASS_CLASSES)],
                    ['name' => 'tipo', 'label' => 'Tipo de comprobante', 'type' => 'select', 'required' => true, 'options' => $types->map(fn ($t) => [$t->id, "{$t->type}-{$t->code} - {$t->name}"])->all()],
                    ['name' => 'desde', 'label' => 'Consecutivo inicial', 'type' => 'number', 'required' => true],
                    ['name' => 'hasta', 'label' => 'Consecutivo final', 'type' => 'number', 'required' => true],
                ],
                'buttons' => [['label' => 'Consultar', 'style' => 'outline', 'data' => ['preview' => 1]], ['label' => 'Aceptar', 'confirm' => 'Se borrarán (anularán) los comprobantes del rango. Esta acción no se puede deshacer. ¿Continuar?']],
            ],
            'initial' => ['comprobante' => $f['comprobante'] ?? null, 'tipo' => isset($f['tipo']) ? (int) $f['tipo'] : null, 'desde' => $f['desde'] ?? null, 'hasta' => $f['hasta'] ?? null],
            'preview' => $preview,
        ]);
    }

    public function massDeleteStore(Request $request, DocumentReverser $reverser): RedirectResponse
    {
        $f = $request->validate([
            'comprobante' => ['required', 'in:'.implode(',', array_keys(self::MASS_CLASSES))], 'tipo' => ['required', 'integer', 'exists:document_types,id'],
            'desde' => ['required', 'integer', 'min:1'], 'hasta' => ['required', 'integer', 'gte:desde'],
        ], ['comprobante.required' => 'Selecciona el comprobante', 'tipo.required' => 'Selecciona el tipo de comprobante', 'desde.required' => 'Indica el consecutivo inicial', 'hasta.required' => 'Indica el consecutivo final', 'hasta.gte' => 'El consecutivo final debe ser mayor o igual al inicial']);
        if ($request->boolean('preview')) {
            return redirect('/contabilidad/borrado-masivo?'.http_build_query($f));
        }
        $docs = $this->massDocs($f);
        if ($docs->count() > 500) {
            throw ValidationException::withMessages(['hasta' => 'El rango tiene más de 500 comprobantes; divídelo en rangos menores.']);
        }
        $done = 0;
        $errors = [];
        foreach ($docs as $d) {
            if ($reason = $this->massBlocker($d)) {
                $errors[] = "{$d->name}: $reason";

                continue;
            }
            try {
                $reverser->annul($d);
                $done++;
            } catch (\Throwable $ex) {
                $errors[] = "{$d->name}: ".($ex instanceof ValidationException ? collect($ex->errors())->flatten()->first() : $ex->getMessage());
            }
        }
        $msg = "Se borraron {$done} comprobante(s).";

        return redirect('/contabilidad/borrado-masivo?'.http_build_query($f))->with($errors ? 'error' : 'success', $errors ? $msg.' No se borraron: '.implode(' | ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : '') : $msg);
    }

    private function massDocs(array $f)
    {
        [$model, $code] = self::MASS_CLASSES[$f['comprobante']] ?? abort(404);
        $type = DocumentType::query()->where('type', $code)->find($f['tipo'])
            ?? throw ValidationException::withMessages(['tipo' => 'El tipo de comprobante no corresponde al comprobante seleccionado']);
        $q = $model::query()->where('document_type_id', $type->id)->where('status', '!=', 'annulled')
            ->whereBetween('number', [(int) ($f['desde'] ?? 0), (int) ($f['hasta'] ?? 0)])->orderBy('number');
        if ($model !== JournalEntry::class) {
            $q->where('type', $code);
        }

        return $q->limit(1000)->get();
    }

    private function massBlocker($d): ?string
    {
        if (($d instanceof SalesDocument) && ($d->dian_status || $d->cufe)) {
            return 'Documento enviado electrónicamente a la DIAN';
        }
        if ($d instanceof JournalEntry && in_array($d->source_type, ['year_close', 'tax_close', 'inventory_costing', 'payroll', 'payroll_payment'], true)) {
            return 'Comprobante de un proceso (costeo, cierres o nómina): elimínalo desde su proceso';
        }

        return app(DocumentReverser::class)->blocker($d);
    }

    private function contactName($d): string
    {
        if ($d instanceof JournalEntry) {
            return (string) DB::table('journal_lines')->join('contacts', 'contacts.id', '=', 'journal_lines.contact_id')->where('journal_entry_id', $d->id)->orderBy('journal_lines.id')->value('contacts.name');
        }

        return (string) Contact::query()->whereKey($d->contact_id)->value('name');
    }

    /* ---------------------------------------------------------------- Medios magneticos */

    public function exogenous(Request $request, ExogenousReport $report)
    {
        $year = (int) $request->input('anio', now()->year - 1);
        $format = (string) $request->input('formato', '1001');
        abort_unless(isset(ExogenousReport::FORMATS[$format]), 404);
        if ($request->boolean('excel')) {
            [$head, $rows] = $report->build($format, $year);

            return ArrayExport::download("formato_{$format}_{$year}.xlsx", $head, $rows, "Formato $format");
        }
        [$head, $rows] = $request->has('formato') ? $report->build($format, $year) : [[], []];

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Asistente de medios magnéticos', 'back' => self::BACK, 'action' => '/contabilidad/medios-magneticos', 'method' => 'get', 'submit' => 'Consultar',
                'info' => 'Genera los formatos de información exógena de la DIAN a partir de la contabilidad. Los conceptos se asignan por cuenta del PUC (config/exogena.php); revísalos con tu contador antes de presentar.',
                'fields' => [
                    ['name' => 'anio', 'label' => 'Año gravable', 'type' => 'year', 'required' => true],
                    ['name' => 'formato', 'label' => 'Formato', 'type' => 'select', 'options' => collect(ExogenousReport::FORMATS)->map(fn ($l, $k) => [$k, $l])->values()->all(), 'required' => true],
                ],
                'links' => $request->has('formato') ? [['label' => 'Descargar Excel', 'href' => "/contabilidad/medios-magneticos?anio={$year}&formato={$format}&excel=1"]] : [],
            ],
            'initial' => ['anio' => $year, 'formato' => $format],
            'preview' => $request->has('formato') ? [
                'title' => ExogenousReport::FORMATS[$format]." ({$year})", 'text' => count($rows).' registros'.(count($rows) > 300 ? '; se muestran los primeros 300, descarga el Excel para verlos todos.' : '.'),
                'columns' => $head, 'rows' => array_slice($rows, 0, 300), 'money' => array_values(array_filter(array_keys($head), fn ($i) => $i >= ($format === '1005' || $format === '1006' ? 12 : 13))),
            ] : null,
        ]);
    }

    /* ---------------------------------------------------------------- Certificados de retencion e informe municipal */

    private const WITHHOLDINGS = ['renta' => ['2365', 'Retención en la fuente a título de renta'], 'iva' => ['2367', 'Retención a título de IVA'], 'ica' => ['2368', 'Retención a título de industria y comercio (ICA)']];

    public function certificates(Request $request)
    {
        $type = $request->input('tipo', 'renta');
        abort_unless(isset(self::WITHHOLDINGS[$type]), 404);
        $year = (int) $request->input('anio', now()->year - 1);
        $from = $request->input('desde') ?: "$year-01-01";
        $to = $request->input('hasta') ?: "$year-12-31";
        $data = $request->has('tipo') ? $this->withholdings($type, $from, $to, $request->input('contact_id')) : [];

        if ($request->boolean('imprimir')) {
            $company = Company::query()->find(CurrentCompany::id());

            return view('certificates.withholding', ['data' => $data, 'company' => $company, 'title' => self::WITHHOLDINGS[$type][1], 'from' => $from, 'to' => $to,
                'city' => DB::table('municipalities')->where('code', $company?->municipality_code)->value('name')]);
        }
        $flat = collect($data)->flatMap(fn ($c) => collect($c['lines'])->map(fn ($l) => [$c['identification'], $c['name'], $l['concept'], $l['base'], $l['rate'], $l['value']]))->all();
        if ($request->boolean('excel')) {
            return ArrayExport::download("certificados_{$type}_{$year}.xlsx", ['Identificación', 'Tercero', 'Concepto', 'Base', 'Tarifa', 'Valor retenido'], $flat, 'Certificados');
        }
        $contact = $request->input('contact_id') ? Contact::query()->find($request->input('contact_id')) : null;
        $qs = http_build_query(array_filter(['tipo' => $type, 'anio' => $year, 'desde' => $request->input('desde'), 'hasta' => $request->input('hasta'), 'contact_id' => $request->input('contact_id')]));

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Certificados de retención', 'back' => self::BACK, 'action' => '/contabilidad/certificados-retencion', 'method' => 'get', 'submit' => 'Consultar',
                'info' => 'Certificados de las retenciones practicadas a cada tercero (cuentas 2365 renta, 2367 IVA y 2368 ICA). La base se toma de los costos, gastos, inventarios y activos del mismo comprobante (para IVA, del IVA descontable).',
                'fields' => [
                    ['name' => 'tipo', 'label' => 'Tipo de certificado', 'type' => 'select', 'options' => collect(self::WITHHOLDINGS)->map(fn ($v, $k) => [$k, $v[1]])->values()->all(), 'required' => true],
                    ['name' => 'anio', 'label' => 'Año', 'type' => 'year', 'required' => true],
                    ['name' => 'desde', 'label' => 'Fecha desde (opcional)', 'type' => 'date'],
                    ['name' => 'hasta', 'label' => 'Fecha hasta (opcional)', 'type' => 'date'],
                    ['name' => 'contact_id', 'label' => 'Tercero (vacío = todos)', 'type' => 'lookup', 'url' => '/api/buscar/terceros'],
                ],
                'links' => $request->has('tipo') ? [['label' => 'Imprimir certificados', 'href' => "/contabilidad/certificados-retencion?$qs&imprimir=1"], ['label' => 'Descargar Excel', 'href' => "/contabilidad/certificados-retencion?$qs&excel=1"]] : [],
            ],
            'initial' => ['tipo' => $type, 'anio' => $year, 'desde' => $request->input('desde'), 'hasta' => $request->input('hasta'),
                'contact_id' => $contact?->id, 'contact_id_label' => $contact ? "{$contact->identification} - {$contact->name}" : ''],
            'preview' => $request->has('tipo') ? ['title' => count($data).' terceros', 'columns' => ['Identificación', 'Tercero', 'Concepto', 'Base', 'Tarifa', 'Valor retenido'], 'money' => [3, 5], 'rows' => array_slice($flat, 0, 500),
                'totals' => ['Total', '', '', array_sum(array_column($flat, 3)), '', array_sum(array_column($flat, 5))]] : null,
        ]);
    }

    public function municipal(Request $request, ExogenousReport $report)
    {
        $year = (int) $request->input('anio', now()->year - 1);
        $data = $request->has('anio') ? $this->withholdings('ica', "$year-01-01", "$year-12-31", null) : [];
        $contacts = Contact::query()->whereIn('id', array_keys($data))->get()->keyBy('id');
        $rows = collect($data)->map(fn ($c, $id) => [...$report->party((int) $id), DB::table('municipalities')->where('code', $contacts[$id]?->municipality_code)->value('name') ?? '',
            round(array_sum(array_column($c['lines'], 'base')), 0), round(array_sum(array_column($c['lines'], 'value')), 0)])->values()->all();
        $head = ['Tipo de documento', 'Número identificación', 'DV', 'Primer apellido', 'Segundo apellido', 'Primer nombre', 'Otros nombres', 'Razón social', 'Dirección', 'Código dpto.', 'Código mcp.', 'País', 'Municipio', 'Base retención', 'Retención ICA practicada'];
        if ($request->boolean('excel')) {
            return ArrayExport::download("medios_municipales_{$year}.xlsx", $head, $rows, 'ReteICA');
        }

        return Inertia::render('SimpleForm', [
            'page' => [
                'title' => 'Informe auxiliar medios magnéticos distritales y/o municipales', 'back' => self::BACK, 'action' => '/contabilidad/medios-municipales', 'method' => 'get', 'submit' => 'Consultar',
                'info' => 'Retenciones de industria y comercio practicadas por tercero (cuenta 2368) con su base, para el reporte de información exógena municipal.',
                'fields' => [['name' => 'anio', 'label' => 'Año gravable', 'type' => 'year', 'required' => true]],
                'links' => $request->has('anio') ? [['label' => 'Descargar Excel', 'href' => "/contabilidad/medios-municipales?anio={$year}&excel=1"]] : [],
            ],
            'initial' => ['anio' => $year],
            'preview' => $request->has('anio') ? ['title' => count($rows).' terceros', 'columns' => $head, 'money' => [13, 14], 'rows' => $rows] : null,
        ]);
    }

    /** @return array<int, array{identification:string, name:string, address:?string, lines: list<array{concept:string, base:float, rate:string, value:float}>}> */
    private function withholdings(string $type, string $from, string $to, $contactId): array
    {
        $prefix = self::WITHHOLDINGS[$type][0];
        $lines = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])->where('account_code', 'like', "$prefix%")
            ->whereNotNull('contact_id')->when($contactId, fn ($q, $c) => $q->where('contact_id', $c))
            ->groupBy('contact_id', 'account_code', 'document')->selectRaw('contact_id, account_code, document, sum(credit)-sum(debit) v')->get();
        $docs = $lines->pluck('document')->unique();
        $basePrefixes = $type === 'iva' ? ['2408'] : ['5', '6', '7', '14', '15'];
        $bases = collect();
        foreach ($docs->chunk(500) as $chunk) {
            $bases = $bases->union(DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereIn('document', $chunk)
                ->where(fn ($q) => collect($basePrefixes)->each(fn ($p) => $q->orWhere('account_code', 'like', "$p%")))
                ->groupBy('document')->selectRaw('document, sum(debit)-sum(credit) v')->pluck('v', 'document'));
        }
        $names = Account::query()->whereIn('code', $lines->pluck('account_code')->unique())->pluck('name', 'code');
        $out = [];
        foreach ($lines->groupBy('contact_id') as $cid => $rows) {
            $byAccount = [];
            foreach ($rows as $r) {
                $a = &$byAccount[$r->account_code];
                $a ??= ['concept' => $names[$r->account_code] ?? $r->account_code, 'base' => 0.0, 'value' => 0.0];
                $a['value'] += (float) $r->v;
                $a['base'] += (float) $r->v != 0 ? (float) ($bases[$r->document] ?? 0) : 0;
                unset($a);
            }
            $byAccount = array_filter($byAccount, fn ($a) => $a['value'] >= 1);
            if (! $byAccount) {
                continue;
            }
            $c = Contact::query()->find($cid);
            $out[$cid] = [
                'identification' => $c?->identification.($c?->dv !== null && $c?->dv !== '' ? '-'.$c->dv : ''), 'name' => $c?->name ?? '', 'address' => $c?->address,
                'lines' => array_values(array_map(fn ($a) => $a + ['rate' => $a['base'] > 0 ? round($a['value'] / $a['base'] * ($type === 'ica' ? 1000 : 100), 2).($type === 'ica' ? ' x mil' : ' %') : ''], $byAccount)),
            ];
        }

        return $out;
    }

    private function list(string $heading, string $url, $rows, array $columns, array $extra, array $filters): Response
    {
        return Inertia::render('GenericList', [
            'view' => ['title' => 'Contabilidad', 'tab' => 'Más procesos', 'heading' => $heading, 'url' => $url, 'columns' => $columns] + $extra,
            'rows' => $rows,
            'tabs' => DashboardController::tabs('contabilidad', 'Más procesos'),
            'filters' => $filters,
        ]);
    }
}
