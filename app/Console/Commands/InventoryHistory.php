<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye el kárdex anterior a la apertura del inventario en Friigo como lo muestra Siigo: ventas y notas crédito importadas
 * al costo de los comprobantes de costeo CC-3 del mes (repartido por unidad; esos comprobantes no se listan salvo que el mes no
 * tenga unidades vendidas), compras al subtotal y comprobantes de inventario por débito - crédito. La diferencia de unidades con
 * el saldo inicial (movimientos anteriores a la historia importada) queda como un renglón "Saldo inicial".
 */
class InventoryHistory extends Command
{
    protected $signature = 'inventory:history {--company=}';

    protected $description = 'Reconstruye la historia del kárdex con los documentos importados de Siigo';

    public function handle(): int
    {
        $companies = $this->option('company') ? [(int) $this->option('company')]
            : DB::table('inventory_movements')->where('concept', 'saldo_inicial')->distinct()->pluck('company_id')->all();

        foreach ($companies as $cid) {
            $rows = $this->rows((int) $cid);
            DB::transaction(function () use ($cid, $rows) {
                DB::table('inventory_history')->where('company_id', $cid)->delete();
                DB::statement("insert into inventory_history (company_id, product_id, date, position, document, quantity, value, balance_quantity, balance_value)
                    select $cid, x.product_id, x.date, row_number() over (order by x.product_id, x.date, x.src, x.rid), x.doc, x.q, x.v, sum(x.q) over w, sum(x.v) over w
                    from ($rows) x window w as (partition by x.product_id order by x.date, x.src, x.rid rows unbounded preceding)");
            });
            $this->info("Empresa $cid: ".DB::table('inventory_history')->where('company_id', $cid)->count().' renglones');
        }

        return self::SUCCESS;
    }

    private function rows(int $cid): string
    {
        $sales = 'from sales_documents d join sales_document_items i on i.sales_document_id = d.id';
        $sold = "where d.company_id = $cid and d.source <> 'erp' and d.type in ('FV', 'NC') and d.status <> 'annulled' and i.product_id is not null";
        $costing = "select c.product_id, c.y, c.mo, c.c, c.d, c.doc, n.q from (
                select jl.product_id, year(je.date) y, month(je.date) mo, sum(jl.credit - jl.debit) c, max(je.date) d, max(je.name) doc
                from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id join accounts a on a.id = jl.account_id
                where je.company_id = $cid and je.status <> 'annulled' and je.name like 'CC-3-%' and jl.product_id is not null and a.code like '14%'
                group by 1, 2, 3) c
            left join (select i.product_id, year(d.date) y, month(d.date) mo, sum(case when d.type = 'NC' then -i.quantity else i.quantity end) q $sales $sold group by 1, 2, 3) n
                on n.product_id = c.product_id and n.y = c.y and n.mo = c.mo";

        $hist = "select i.product_id, d.date, 1 src, i.id rid, d.name doc, case when d.type = 'NC' then i.quantity else -i.quantity end q,
                case when d.type = 'NC' then 1 else -1 end * coalesce(i.quantity * u.c / nullif(u.q, 0), 0) v
            $sales left join ($costing) u on u.product_id = i.product_id and u.y = year(d.date) and u.mo = month(d.date)
            $sold
            union all
            select k.product_id, k.d, 3, 0, k.doc, 0, -k.c from ($costing) k where coalesce(k.q, 0) = 0 and k.c <> 0
            union all
            select i.product_id, d.date, 2, i.id, d.name, case when d.type in ('FC', 'DS') then i.quantity else -i.quantity end,
                case when d.type in ('FC', 'DS') then i.subtotal else -i.subtotal end
            from purchase_documents d join purchase_document_items i on i.purchase_document_id = d.id
            where d.company_id = $cid and d.source <> 'erp' and d.type in ('FC', 'DS', 'ND', 'NA') and d.status <> 'annulled' and i.product_id is not null
            union all
            select jl.product_id, je.date, 3, jl.id, je.name, case when jl.debit > 0 then jl.quantity else -jl.quantity end, jl.debit - jl.credit
            from journal_lines jl join journal_entries je on je.id = jl.journal_entry_id join accounts a on a.id = jl.account_id
            where je.company_id = $cid and je.source <> 'erp' and je.status <> 'annulled' and jl.product_id is not null and jl.quantity <> 0 and a.code like '14%'";

        return "$hist
            union all
            select o.product_id, '2023-01-01', 0, o.id, 'Saldo inicial', o.q - coalesce(h.q, 0), (o.q - coalesce(h.q, 0)) * o.unit_cost
            from (select product_id, min(id) id, sum(quantity) q, max(unit_cost) unit_cost from inventory_movements
                where company_id = $cid and concept = 'saldo_inicial' group by product_id) o
            left join (select product_id, sum(q) q from ($hist) hh group by product_id) h on h.product_id = o.product_id
            where abs(o.q - coalesce(h.q, 0)) > 0.0001";
    }
}
