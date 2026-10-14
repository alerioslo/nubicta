<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/** Carga el catalogo de cuentas exportado de Siigo (database/data/puc.json). */
class PucLoader
{
    public function load(int $companyId, ?string $path = null): int
    {
        $rows = json_decode(file_get_contents($path ?? database_path('data/puc.json')), true);
        usort($rows, fn ($a, $b) => strlen($a['code']) <=> strlen($b['code']) ?: strcmp($a['code'], $b['code']));

        AuditLog::$enabled = false;
        $ids = Account::withoutGlobalScopes()->where('company_id', $companyId)->pluck('id', 'code')->all();
        $count = 0;

        DB::transaction(function () use ($rows, $companyId, &$ids, &$count) {
            foreach ($rows as $r) {
                $code = $r['code'];
                $parentId = null;
                for ($len = strlen($code) - 1; $len >= 1; $len--) {
                    if (isset($ids[substr($code, 0, $len)])) {
                        $parentId = $ids[substr($code, 0, $len)];
                        break;
                    }
                }

                $attributes = [
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'name' => $r['name'],
                    'level' => Account::levelForCode($code),
                    'nature' => Account::natureForCode($code),
                    'category' => $r['category'],
                    'relation' => $r['relation'] === 'Sin asignar' ? null : $r['relation'],
                    'due_type' => $r['due_type'],
                    'fiscal_difference' => $r['fiscal_difference'],
                    'transactional' => $r['transactional'],
                    'active' => $r['active'],
                ];

                $account = Account::withoutGlobalScopes()->updateOrCreate(['company_id' => $companyId, 'code' => $code], $attributes);
                $ids[$code] = $account->id;
                $count++;
            }
        });
        AuditLog::$enabled = true;

        return $count;
    }
}
