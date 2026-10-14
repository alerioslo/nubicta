<?php

namespace App\Console\Commands;

use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La API de Siigo no entrega las cuentas de los grupos de inventario ni de las formas de pago.
 * Se deducen del libro: la cuenta que mas mueve cada parametro en los documentos que lo usan solo.
 */
class DeriveAccounts extends Command
{
    protected $signature = 'accounting:derive-accounts {--company=1} {--force : Reemplaza tambien las cuentas ya asignadas}';

    protected $description = 'Asigna cuentas contables a grupos de inventario y formas de pago a partir del libro de Siigo';

    public function handle(): int
    {
        $company = (int) $this->option('company');
        CurrentCompany::set($company);
        $since = now()->subMonths(12)->toDateString();
        $accountId = fn (?string $code) => $code ? DB::table('accounts')->where('company_id', $company)->where('code', $code)->value('id') : null;

        $groups = DB::table('account_groups')->where('company_id', $company)->get();
        foreach ($groups as $g) {
            $codes = DB::select(<<<'SQL'
                select g.account_code code, count(*) n
                from (select sales_document_id, min(product_id) pid from sales_document_items group by sales_document_id having count(*) = 1) one
                join sales_documents d on d.id = one.sales_document_id and d.type = 'FV' and d.date >= ?
                join products p on p.id = one.pid and p.account_group_id = ?
                join ledger_lines g on g.company_id = d.company_id and g.document = d.name
                where g.account_code like '4135%' or g.account_code like '14%'
                group by g.account_code order by n desc
                SQL, [$since, $g->id]);
            $income = collect($codes)->first(fn ($r) => str_starts_with($r->code, '4135'))?->code;
            $inventory = collect($codes)->first(fn ($r) => str_starts_with($r->code, '14'))?->code;
            $suffix = $income ? substr($income, -2) : null;
            $values = [
                'income_account_id' => $accountId($income),
                'inventory_account_id' => $accountId($inventory),
                'cost_account_id' => $accountId($suffix ? '613505'.$suffix : null),
                'return_account_id' => $accountId($suffix ? '417505'.$suffix : null),
            ];
            $this->apply('account_groups', $g, $values);
            $this->line(sprintf('Grupo %-22s ingreso %s inventario %s', $g->name, $income ?? '-', $inventory ?? '-'));
        }

        foreach (DB::table('payment_methods')->where('company_id', $company)->get() as $m) {
            $sales = in_array($m->type, ['Cartera', 'CarteraProveedor'], true);
            $code = $this->paymentAccount($m->id, $since, $sales) ?? ($sales ? null : $this->paymentAccount($m->id, $since, true));
            $code ??= $this->byName($m->name);
            $this->apply('payment_methods', $m, ['account_id' => $accountId($code)]);
            $this->line(sprintf('Forma de pago %-40s %s', $m->name, $code ?? '-'));
        }

        return self::SUCCESS;
    }

    private function paymentAccount(int $methodId, string $since, bool $sales): ?string
    {
        [$table, $fk, $docs, $side, $type] = $sales
            ? ['sales_document_payments', 'sales_document_id', 'sales_documents', 'debit', 'FV']
            : ['purchase_document_payments', 'purchase_document_id', 'purchase_documents', 'credit', 'FC'];
        $exclude = $sales ? "g.account_code not like '6%' and g.account_code not like '1355%' and g.account_code not like '5305%'" : "g.account_code not like '23%' and g.account_code not like '24%'";

        return DB::selectOne(<<<SQL
            select g.account_code code, count(*) n
            from (select $fk doc_id, min(payment_method_id) pm from $table group by $fk having count(*) = 1) one
            join $docs d on d.id = one.doc_id and d.type = ? and d.date >= ?
            join ledger_lines g on g.company_id = d.company_id and g.document = d.name and g.$side > 0
            where one.pm = ? and $exclude
            group by g.account_code order by n desc limit 1
            SQL, [$type, $since, $methodId])?->code;
    }

    /** Formas de pago sin uso en el ultimo año: cuenta segun el nombre. */
    private function byName(string $name): ?string
    {
        $n = mb_strtolower($name);

        return match (true) {
            str_contains($n, 'anticipo') && str_contains($n, 'emplead') => '13301501',
            str_contains($n, 'anticipo') && str_contains($n, 'proveedor') => '13300501',
            str_contains($n, 'anticp') || (str_contains($n, 'anticipo') && str_contains($n, 'client')) => '28050501',
            str_contains($n, 'honorario') => '23352501',
            str_contains($n, 'otras cuentas por pagar') => '23809501',
            str_contains($n, 'proveedor') || str_contains($n, 'compras') => '22050501',
            str_contains($n, 'client') || str_contains($n, 'crédito') || str_contains($n, 'credito') => '13050501',
            str_contains($n, 'efectivo') => '11050501',
            default => null,
        };
    }

    private function apply(string $table, object $row, array $values): void
    {
        $values = array_filter($values, fn ($v, $k) => $v && ($this->option('force') || empty($row->{$k})), ARRAY_FILTER_USE_BOTH);
        if ($values) {
            DB::table($table)->where('id', $row->id)->update($values);
        }
    }
}
