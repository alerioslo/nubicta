<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Services\Documents\InventoryRegistrar;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\Numbering;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Saldos iniciales de Siigo (Contabilidad > Mas procesos): cartera, proveedores, inventario y otras cuentas.
 * Todo se contabiliza en el CC-999 contra la cuenta "Saldos iniciales por conciliar"; las facturas de cartera
 * y proveedores quedan como documentos SI con saldo para recaudarlas o pagarlas con los recibos.
 */
class OpeningBalances
{
    public const KINDS = [
        'cuentas-por-cobrar' => 'Saldos iniciales de cuentas por cobrar',
        'cuentas-por-pagar' => 'Saldos iniciales de cuentas por pagar',
        'inventario' => 'Saldos iniciales de inventario',
        'otras-cuentas' => 'Saldos iniciales de otras cuentas contables',
    ];

    public const OPERATIONS = [
        'cuentas-por-cobrar' => ['factura' => 'Factura de venta', 'anticipo' => 'Anticipo de cartera'],
        'cuentas-por-pagar' => ['factura' => 'Factura de compra', 'anticipo' => 'Anticipo proveedores'],
    ];

    public const TEMPLATES = [
        'cuentas-por-cobrar' => ['Identificación cliente', 'Tipo de operación (Factura de venta / Anticipo de cartera)', 'Prefijo', 'N° comprobante', 'N° de cuota', 'Fecha de vencimiento (dd/mm/aaaa)', 'Valor', 'Cuenta contable (opcional)'],
        'cuentas-por-pagar' => ['Identificación proveedor', 'Tipo de operación (Factura de compra / Anticipo proveedores)', 'Prefijo', 'N° comprobante', 'N° de cuota', 'Fecha de vencimiento (dd/mm/aaaa)', 'Valor', 'Cuenta contable (opcional)'],
        'inventario' => ['Código producto', 'Código centro de costo (opcional)', 'Cantidad', 'Costo unitario'],
        'otras-cuentas' => ['Cuenta contable', 'Identificación tercero', 'Código centro de costo (opcional)', 'Impuesto (opcional)', 'Débito', 'Crédito'],
    ];

    public function __construct(
        private readonly AccountResolver $accounts,
        private readonly JournalRegistrar $journals,
        private readonly Numbering $numbering,
        private readonly InventoryRegistrar $inventory,
    ) {}

    public function store(string $kind, array $data): JournalEntry
    {
        $date = $data['date'] ?? null;
        if (! $date || ! strtotime($date)) {
            $this->fail('date', 'Indica la fecha de los saldos iniciales');
        }
        $rows = collect($data['items'] ?? [])->values();

        return match ($kind) {
            'cuentas-por-cobrar', 'cuentas-por-pagar' => $this->portfolio($kind, $date, $rows, $data),
            'inventario' => $this->stock($date, $rows, $data),
            'otras-cuentas' => $this->others($date, $rows, $data),
        };
    }

    private function portfolio(string $kind, string $date, $rows, array $data): JournalEntry
    {
        $sales = $kind === 'cuentas-por-cobrar';
        $rows = $rows->filter(fn ($r) => ! empty($r['contact_id']) || (float) ($r['value'] ?? 0) != 0)->values();
        if ($rows->isEmpty()) {
            $this->fail('items', 'Agrega al menos un saldo');
        }
        $conciliate = $this->conciliate();
        $lines = [];
        $docs = [];
        $seen = [];
        foreach ($rows as $k => $r) {
            $n = $k + 1;
            $contact = Contact::query()->find($r['contact_id'] ?? 0) ?? $this->fail('items', "Línea $n: selecciona el ".($sales ? 'cliente' : 'proveedor'));
            $op = $r['operation'] ?? '';
            if (! isset(self::OPERATIONS[$kind][$op])) {
                $this->fail('items', "Línea $n: selecciona el tipo de operación");
            }
            $value = round((float) ($r['value'] ?? 0), 2);
            if ($value <= 0) {
                $this->fail('items', "Línea $n: el valor debe ser mayor a cero");
            }
            $number = trim((string) ($r['number'] ?? ''));
            if ($op === 'factura') {
                if ($number === '' || ! ctype_digit($number)) {
                    $this->fail('items', "Línea $n: indica el número del comprobante (solo números)");
                }
                if (empty($r['due_date']) || ! strtotime($r['due_date'])) {
                    $this->fail('items', "Línea $n: indica la fecha de vencimiento");
                }
                $prefix = strtoupper(trim((string) ($r['prefix'] ?? '')));
                $name = substr(($prefix !== '' ? "$prefix-" : 'SI-').$number.((int) ($r['quote'] ?? 1) > 1 ? '-'.(int) $r['quote'] : ''), 0, 40);
                $model = $sales ? SalesDocument::class : PurchaseDocument::class;
                if (isset($seen[$contact->id.$name]) || $model::query()->where('type', 'SI')->where('contact_id', $contact->id)->where('name', $name)->where('status', '!=', 'annulled')->exists()) {
                    $this->fail('items', "Línea $n: el saldo $name ya está registrado para este tercero");
                }
                $seen[$contact->id.$name] = true;
                $docs[] = compact('contact', 'prefix', 'number', 'name', 'value') + ['due_date' => $r['due_date']];
            }
            $default = $sales ? ($op === 'factura' ? 'customers' : 'customer_advances') : ($op === 'factura' ? 'suppliers' : 'supplier_advances');
            $account = ! empty($r['account_id']) ? (int) $r['account_id'] : $this->accounts->default($default);
            $desc = self::OPERATIONS[$kind][$op].($number !== '' ? ' '.trim(($r['prefix'] ?? '').' '.$number) : '');
            $debitSide = $sales === ($op === 'factura');
            $lines[] = ['account_id' => $account, 'contact' => $contact, 'description' => $desc, 'debit' => $debitSide ? $value : 0, 'credit' => $debitSide ? 0 : $value];
            $lines[] = ['account_id' => $conciliate, 'contact' => $contact, 'description' => $desc, 'debit' => $debitSide ? 0 : $value, 'credit' => $debitSide ? $value : 0];
        }

        return DB::transaction(function () use ($date, $data, $lines, $docs, $sales, $kind) {
            $entry = $this->journals->store($this->type(), ['date' => $date, 'observations' => $this->observations($kind, $data)], $lines, 'opening_balance');
            foreach ($docs as $d) {
                ($sales ? SalesDocument::class : PurchaseDocument::class)::query()->create([
                    'company_id' => CurrentCompany::id(), 'source' => 'erp', 'type' => 'SI', 'number' => (int) $d['number'], 'name' => $d['name'],
                    'date' => $date, 'due_date' => $d['due_date'], 'contact_id' => $d['contact']->id, 'subtotal' => $d['value'], 'total' => $d['value'], 'balance' => $d['value'],
                    'related_name' => $entry->name, 'observations' => 'Saldo inicial', 'status' => 'issued', 'created_by' => Auth::id(),
                ] + ($sales ? ['prefix' => $d['prefix'] ?: null] : ['supplier_prefix' => $d['prefix'] ?: null, 'supplier_number' => $d['number']]));
            }

            return $entry;
        });
    }

    private function stock(string $date, $rows, array $data): JournalEntry
    {
        $items = $rows->filter(fn ($r) => ! empty($r['product_id']))->map(fn ($r) => [
            'product_id' => (int) $r['product_id'], 'quantity' => (float) ($r['quantity'] ?? 0), 'cost' => (float) ($r['cost'] ?? 0), 'account_id' => $this->conciliate(),
        ])->values()->all();
        if (! $items) {
            $this->fail('items', 'Agrega al menos un producto con cantidad y costo');
        }

        return $this->inventory->register('saldo', ['date' => $date, 'contact_id' => $data['contact_id'] ?? null, 'items' => $items, 'observations' => $this->observations('inventario', $data)]);
    }

    private function others(string $date, $rows, array $data): JournalEntry
    {
        $rows = $rows->filter(fn ($r) => ! empty($r['account_id']))->values();
        if ($rows->isEmpty()) {
            $this->fail('items', 'Agrega al menos una cuenta con saldo');
        }
        $conciliate = $this->conciliate();
        $lines = [];
        $net = [];
        foreach ($rows as $k => $r) {
            $n = $k + 1;
            $account = Account::query()->find($r['account_id']) ?? $this->fail('items', "Línea $n: selecciona la cuenta");
            if (! $account->transactional) {
                $this->fail('items', "Línea $n: la cuenta {$account->code} no es de movimiento");
            }
            if ($account->due_type || str_starts_with($account->code, '14')) {
                $this->fail('items', "Línea $n: la cuenta {$account->code} se carga desde los saldos iniciales de ".($account->due_type === 'cartera' ? 'cuentas por cobrar' : ($account->due_type ? 'cuentas por pagar' : 'inventario')));
            }
            $debit = round((float) ($r['debit'] ?? 0), 2);
            $credit = round((float) ($r['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) {
                $this->fail('items', "Línea $n: solo se pueden cargar valores en positivo");
            }
            if (($debit > 0) === ($credit > 0)) {
                $this->fail('items', "Línea $n: indica el valor en débito o en crédito");
            }
            $contact = ! empty($r['contact_id']) ? Contact::query()->find($r['contact_id']) : null;
            if ($account->requires_third_party && ! $contact) {
                $this->fail('items', "Línea $n: la cuenta {$account->code} exige tercero");
            }
            $tax = ! empty($r['tax_id']) ? Tax::query()->find($r['tax_id']) : null;
            $center = ! empty($r['cost_center_id']) ? (int) $r['cost_center_id'] : null;
            $desc = 'Saldo inicial'.($tax ? " ({$tax->name})" : '');
            $lines[] = ['account_id' => $account->id, 'contact' => $contact, 'description' => $desc, 'debit' => $debit, 'credit' => $credit, 'cost_center_id' => $center];
            $key = $contact?->id ?? 0;
            $net[$key] = ($net[$key] ?? 0) + $debit - $credit;
        }
        foreach ($net as $cid => $v) {
            $v = round($v, 2);
            if ($v != 0) {
                $lines[] = ['account_id' => $conciliate, 'contact' => $cid ? Contact::query()->find($cid) : null, 'description' => 'Saldos iniciales por conciliar', 'debit' => $v < 0 ? -$v : 0, 'credit' => $v > 0 ? $v : 0];
            }
        }
        return $this->journals->store($this->type(), ['date' => $date, 'observations' => $this->observations('otras-cuentas', $data)], $lines, 'opening_balance');
    }

    /** Lee la plantilla de Excel y devuelve las filas listas para la grilla (con etiquetas de los buscadores). */
    public function parse(string $kind, string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
        array_shift($sheet);
        $rows = [];
        $errors = [];
        foreach (array_slice($sheet, 0, 500) as $i => $c) {
            $c = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $c);
            if (! array_filter($c, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $n = $i + 2;
            try {
                $rows[] = match ($kind) {
                    'cuentas-por-cobrar', 'cuentas-por-pagar' => $this->portfolioRow($kind, $c),
                    'inventario' => $this->stockRow($c),
                    'otras-cuentas' => $this->otherRow($c),
                };
            } catch (\RuntimeException $e) {
                $errors[] = "Fila $n: ".$e->getMessage();
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    private function portfolioRow(string $kind, array $c): array
    {
        $contact = $this->contact($c[0] ?? '');
        $label = mb_strtolower((string) ($c[1] ?? ''));
        $op = str_contains($label, 'anticipo') ? 'anticipo' : 'factura';
        $account = ! empty($c[7]) ? $this->account((string) $c[7]) : null;

        return ['contact_id' => $contact->id, 'contact_label' => "{$contact->identification} - {$contact->name}", 'operation' => $op, 'prefix' => (string) ($c[2] ?? ''),
            'number' => (string) ($c[3] ?? ''), 'quote' => (int) ($c[4] ?? 1) ?: 1, 'due_date' => $this->date($c[5] ?? null), 'value' => $this->number($c[6] ?? 0),
            'account_id' => $account?->id, 'account_label' => $account ? "{$account->code} - {$account->name}" : ''];
    }

    private function stockRow(array $c): array
    {
        $p = Product::query()->where('code', (string) ($c[0] ?? ''))->first() ?? throw new \RuntimeException("no existe el producto {$c[0]}");
        $center = ! empty($c[1]) ? CostCenter::query()->where('code', (string) $c[1])->first() : null;

        return ['product_id' => $p->id, 'product_label' => "{$p->code} - {$p->name}", 'description' => $p->name, 'cost_center_id' => $center?->id,
            'quantity' => $this->number($c[2] ?? 0), 'cost' => $this->number($c[3] ?? 0)];
    }

    private function otherRow(array $c): array
    {
        $a = $this->account((string) ($c[0] ?? ''));
        $contact = ! empty($c[1]) ? $this->contact($c[1]) : null;
        $center = ! empty($c[2]) ? CostCenter::query()->where('code', (string) $c[2])->first() : null;
        $tax = ! empty($c[3]) ? Tax::query()->where('name', 'like', '%'.$c[3].'%')->first() : null;

        return ['account_id' => $a->id, 'account_label' => "{$a->code} - {$a->name}", 'contact_id' => $contact?->id, 'contact_label' => $contact ? "{$contact->identification} - {$contact->name}" : '',
            'cost_center_id' => $center?->id, 'tax_id' => $tax?->id, 'debit' => $this->number($c[4] ?? 0), 'credit' => $this->number($c[5] ?? 0)];
    }

    private function contact(mixed $id): Contact
    {
        $id = preg_replace('/[^0-9A-Za-z]/', '', explode('-', (string) $id)[0]);

        return Contact::query()->where('identification', $id)->first() ?? throw new \RuntimeException("no existe el tercero $id");
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', trim($code))->first() ?? throw new \RuntimeException("no existe la cuenta $code");
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', (string) $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return ($t = strtotime((string) $v)) ? date('Y-m-d', $t) : throw new \RuntimeException("fecha inválida $v");
    }

    private function number(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : (float) str_replace(',', '', (string) $v);
    }

    private function conciliate(): int
    {
        return $this->accounts->id('99999999');
    }

    private function type()
    {
        return $this->numbering->journalType('999', 'Saldos iniciales');
    }

    private function observations(string $kind, array $data): string
    {
        return trim(self::KINDS[$kind].'. '.($data['observations'] ?? ''), '. ');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
