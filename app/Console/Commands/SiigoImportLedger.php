<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\FixedAsset;
use App\Models\FixedAssetGroup;
use App\Models\LedgerLine;
use App\Support\CurrentCompany;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Importa el libro contable completo (Movimiento auxiliar de tercero por cuenta de Siigo) y los activos fijos. */
class SiigoImportLedger extends Command
{
    protected $signature = 'siigo:import-ledger {--company=1} {--dir= : carpeta con libro_auxiliar.csv y activos_fijos.json}
        {--xlsx= : Excel "Movimiento auxiliar de tercero por cuenta contable" de Siigo; reemplaza solo las fechas que trae}';

    protected $description = 'Importa el libro auxiliar contable y los activos fijos exportados de Siigo';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        CurrentCompany::set($companyId);
        AuditLog::$enabled = false;
        if ($xlsx = $this->option('xlsx')) {
            $this->ledgerExcel($xlsx, $companyId);

            return self::SUCCESS;
        }
        $dir = rtrim($this->option('dir') ?: storage_path('app/siigo-exports'), '/');

        $csv = "$dir/libro_auxiliar.csv";
        if (is_file($csv)) {
            $this->ledger($csv, $companyId);
        } else {
            $this->warn('No existe libro_auxiliar.csv');
        }
        if (is_file("$dir/activos_fijos.json")) {
            $this->assets("$dir/activos_fijos.json");
        }

        return self::SUCCESS;
    }

    private function ledger(string $csv, int $companyId): void
    {
        $accounts = Account::pluck('id', 'code')->all();
        $contacts = Contact::query()->orderBy('branch_office')->get(['id', 'identification'])
            ->groupBy('identification')->map(fn ($g) => $g->first()->id)->all();

        $created = 0;
        $fh = fopen($csv, 'r');
        while (($r = fgetcsv($fh)) !== false) {
            if (! isset($accounts[$r[5]])) {
                $accounts[$r[5]] = $this->createAccount($r[5], $r[6]);
                $created++;
            }
        }
        $this->info("Cuentas creadas desde el libro: $created");

        DB::table('ledger_lines')->where('company_id', $companyId)->where('source', 'siigo')->delete();
        rewind($fh);
        $batch = [];
        $n = 0;
        $debit = $credit = 0;
        while (($r = fgetcsv($fh)) !== false) {
            [$kind, $date, $ident, $branch, $name, $code, , $doc, $d, $c] = $r;
            $batch[] = [
                'company_id' => $companyId, 'date' => $date, 'account_code' => $code, 'account_id' => $accounts[$code] ?? null,
                'identification' => $ident ?: null, 'branch' => $branch ?: '0', 'contact_name' => $name ?: null,
                'contact_id' => $contacts[$ident] ?? null, 'document' => $doc,
                'document_type' => $kind === 'S' ? 'SI' : strtok($doc, '-'), 'debit' => $d, 'credit' => $c, 'source' => 'siigo',
            ];
            $debit += (float) $d;
            $credit += (float) $c;
            if (count($batch) === 2000) {
                DB::table('ledger_lines')->insert($batch);
                $n += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table('ledger_lines')->insert($batch);
            $n += count($batch);
        }
        fclose($fh);
        $this->info(sprintf('Libro: %d lineas, debito %s, credito %s', $n, number_format($debit, 2), number_format($credit, 2)));
    }

    /** Actualizacion incremental: borra las lineas de Siigo de los dias del reporte y las vuelve a cargar. */
    private function ledgerExcel(string $path, int $companyId): void
    {
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        $accounts = Account::pluck('id', 'code')->all();
        $contacts = Contact::query()->orderBy('branch_office')->get(['id', 'identification'])
            ->groupBy('identification')->map(fn ($g) => $g->first()->id)->all();
        $lines = [];
        foreach ($rows as $r) {
            if (empty($r[5]) || ! preg_match('/^\d+$/', (string) $r[3]) || ! preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', (string) $r[6], $m)) {
                continue;
            }
            $code = (string) $r[3];
            $accounts[$code] ??= $this->createAccount($code, (string) $r[4]);
            $ident = trim((string) $r[0]);
            $lines[] = [
                'company_id' => $companyId, 'date' => "$m[3]-$m[2]-$m[1]", 'account_code' => $code, 'account_id' => $accounts[$code],
                'identification' => $ident ?: null, 'branch' => (string) ($r[1] ?: '0'), 'contact_name' => $r[2] ?: null,
                'contact_id' => $contacts[$ident] ?? null, 'document' => $r[5], 'document_type' => strtok($r[5], '-'),
                'debit' => round((float) $r[8], 2), 'credit' => round((float) $r[9], 2), 'source' => 'siigo',
            ];
        }
        if (! $lines) {
            $this->warn('El archivo no trae movimientos');

            return;
        }
        $dates = array_column($lines, 'date');
        [$from, $to] = [min($dates), max($dates)];
        DB::transaction(function () use ($companyId, $from, $to, $lines) {
            $deleted = DB::table('ledger_lines')->where('company_id', $companyId)->where('source', 'siigo')->whereBetween('date', [$from, $to])->delete();
            foreach (array_chunk($lines, 1000) as $chunk) {
                DB::table('ledger_lines')->insert($chunk);
            }
            $this->info(sprintf('Libro %s a %s: %d lineas reemplazadas por %d (debito %s, credito %s)', $from, $to, $deleted, count($lines),
                number_format(array_sum(array_column($lines, 'debit')), 2), number_format(array_sum(array_column($lines, 'credit')), 2)));
        });
    }

    private function createAccount(string $code, string $name): int
    {
        $level = match (strlen($code)) { 1 => 1, 2 => 2, 4 => 3, 6 => 4, default => 5 };
        $parentCode = match (strlen($code)) { 1 => null, 2 => substr($code, 0, 1), 4 => substr($code, 0, 2), 6 => substr($code, 0, 4), default => substr($code, 0, 6) };

        return Account::create([
            'parent_id' => $parentCode ? Account::where('code', $parentCode)->value('id') : null,
            'code' => $code, 'name' => $name ?: $code, 'level' => $level,
            'nature' => in_array($code[0], ['2', '3', '4'], true) ? 'credito' : 'debito',
            'transactional' => true, 'active' => true,
        ])->id;
    }

    private function assets(string $path): void
    {
        $groups = FixedAssetGroup::pluck('id', 'name')->all();
        $rows = json_decode(file_get_contents($path), true);
        foreach ($rows as $a) {
            [$createdBy, $createdAt] = $this->stamp($a['texts'], 'Creado');
            [$modifiedBy, $modifiedAt] = $this->stamp($a['texts'], 'Modificado');
            FixedAsset::updateOrCreate(['code' => $a['code']], [
                'fixed_asset_group_id' => $groups[$a['group']] ?? null, 'name' => $a['name'], 'active' => $a['status'] === 'Activo',
                'created_by' => $createdBy, 'siigo_created_at' => $createdAt, 'modified_by' => $modifiedBy, 'siigo_modified_at' => $modifiedAt,
            ]);
        }
        $this->info(count($rows).' activos fijos');
    }

    /** Busca "Creado : Usuario dd/mm/aaaa hh:mm hrs." en los textos de la ficha de Siigo. */
    private function stamp(array $texts, string $label): array
    {
        $seq = array_map(fn ($t) => trim($t['t'] ?? ''), $texts);
        $i = array_search($label, $seq, true);
        if ($i === false) {
            return [null, null];
        }
        $user = null;
        foreach (array_slice($seq, $i + 1, 4) as $t) {
            if (preg_match('#(\d{2}/\d{2}/\d{4}) (\d{2}:\d{2})#', $t, $m)) {
                return [$user, Carbon::createFromFormat('d/m/Y H:i', "$m[1] $m[2]", 'UTC')];
            }
            if ($t !== ':' && $t !== '' && ! in_array($t, ['Creado', 'Modificado'], true)) {
                $user = trim($t, ': ');
            }
        }

        return [$user, null];
    }
}
