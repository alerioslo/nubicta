<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Costo promedio ponderado por producto a partir de las compras (Siigo no expone el costo por API). */
class InventoryAverageCost extends Command
{
    protected $signature = 'inventory:average-cost {--company=1}';

    protected $description = 'Calcula products.average_cost con el promedio ponderado de las compras';

    public function handle(): int
    {
        $company = (int) $this->option('company');
        $updated = DB::update("
            update products p
            join (
                select pi.product_id, sum(pi.subtotal) / nullif(sum(pi.quantity), 0) cost
                from purchase_document_items pi
                join purchase_documents d on d.id = pi.purchase_document_id
                where d.company_id = ? and d.type in ('FC', 'DS') and d.status <> 'annulled' and pi.product_id is not null and pi.quantity > 0
                group by pi.product_id
            ) c on c.product_id = p.id
            set p.average_cost = round(c.cost, 6)
            where p.company_id = ?", [$company, $company]);

        $this->info("Productos con costo promedio: $updated");

        return self::SUCCESS;
    }
}
