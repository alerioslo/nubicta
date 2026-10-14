<?php

namespace App\Services\Accounting;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Informacion exogena (medios magneticos DIAN) a partir de la contabilidad: formatos 1001, 1003, 1005, 1006, 1007, 1008 y 1009. */
class ExogenousReport
{
    public const FORMATS = [
        '1001' => '1001 - Pagos o abonos en cuenta y retenciones practicadas',
        '1003' => '1003 - Retenciones en la fuente que le practicaron',
        '1005' => '1005 - Impuesto a las ventas por pagar (descontable)',
        '1006' => '1006 - Impuesto a las ventas por pagar (generado)',
        '1007' => '1007 - Ingresos recibidos',
        '1008' => '1008 - Saldo de cuentas por cobrar al 31 de diciembre',
        '1009' => '1009 - Saldo de cuentas por pagar al 31 de diciembre',
    ];

    private const PARTY = ['Tipo de documento', 'Número identificación', 'DV', 'Primer apellido', 'Segundo apellido', 'Primer nombre', 'Otros nombres', 'Razón social', 'Dirección', 'Código dpto.', 'Código mcp.', 'País'];

    private array $contacts = [];

    /** @return array{0: list<string>, 1: list<array>} */
    public function build(string $format, int $year): array
    {
        $from = "$year-01-01";
        $to = "$year-12-31";

        return match ($format) {
            '1001' => $this->f1001($from, $to),
            '1003' => $this->f1003($from, $to),
            '1005' => $this->iva($from, $to, true),
            '1006' => $this->iva($from, $to, false),
            '1007' => $this->f1007($from, $to),
            '1008' => $this->balances($to, '13', '1008', true),
            '1009' => $this->balances($to, '2', '1009', false),
        };
    }

    private function f1001(string $from, string $to): array
    {
        $map = config('exogena.1001');
        $rows = [];
        foreach ($this->movements($from, $to, ['5', '6', '7', '14', '15']) as $m) {
            $concept = $this->concept($m->account_code, $map);
            if ($concept && $m->contact_id) {
                $rows[$concept.'|'.$m->contact_id] = ($rows[$concept.'|'.$m->contact_id] ?? 0) + (float) $m->d - (float) $m->c;
            }
        }
        $ret = $this->byContact($from, $to, config('exogena.retefuente'), false);
        $iva = $this->byContact($from, $to, config('exogena.reteiva'), false);
        $main = [];
        foreach ($rows as $k => $v) {
            [$concept, $cid] = explode('|', $k);
            if (! isset($main[$cid]) || $v > $rows[$main[$cid].'|'.$cid]) {
                $main[$cid] = $concept;
            }
        }
        foreach (array_keys($ret + $iva) as $cid) {
            if (! isset($main[$cid])) {
                $main[$cid] = '5016';
                $rows['5016|'.$cid] = 0;
            }
        }
        $out = [];
        foreach ($rows as $k => $v) {
            [$concept, $cid] = explode('|', $k);
            $isMain = $main[$cid] === $concept;
            $r = [round($v, 0), 0, 0, 0, $isMain ? round($ret[$cid] ?? 0, 0) : 0, 0, $isMain ? round($iva[$cid] ?? 0, 0) : 0, 0];
            if (array_sum($r) != 0) {
                $out[] = [$concept, ...$this->party((int) $cid), ...$r];
            }
        }

        return [['Concepto', ...self::PARTY, 'Pago o abono en cuenta deducible', 'Pago o abono en cuenta no deducible', 'IVA mayor valor del costo o gasto deducible',
            'IVA mayor valor del costo o gasto no deducible', 'Retención en la fuente practicada renta', 'Retención en la fuente asumida renta',
            'Retención en la fuente practicada IVA régimen común', 'Retención en la fuente practicada IVA no domiciliados'], $this->minor($this->sorted($out), '1001', [13, 14, 15, 16, 17, 18, 19, 20])];
    }

    private function f1003(string $from, string $to): array
    {
        $map = config('exogena.1003');
        $out = [];
        foreach ($this->movements($from, $to, ['1355']) as $m) {
            $v = (float) $m->d - (float) $m->c;
            if ($m->contact_id && abs($v) >= 1) {
                $concept = $this->concept($m->account_code, $map) ?? '1301';
                $key = $concept.'|'.$m->contact_id;
                $out[$key] = ($out[$key] ?? 0) + $v;
            }
        }
        $bases = $this->documentBase($from, $to, '1355', ['4'], true);
        $rows = [];
        foreach ($out as $k => $v) {
            [$concept, $cid] = explode('|', $k);
            $rows[] = [$concept, ...$this->party((int) $cid), round($bases[$cid] ?? 0, 0), round($v, 0)];
        }

        return [['Concepto', ...self::PARTY, 'Valor acumulado del pago o abono sujeto a retención en la fuente', 'Retención que le practicaron'], $this->sorted($rows)];
    }

    private function iva(string $from, string $to, bool $deductible): array
    {
        $rows = [];
        $gross = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])->where('account_code', 'like', config('exogena.iva').'%')
            ->whereNotNull('contact_id')->where('document', 'not like', 'CC-%')->groupBy('contact_id')
            ->selectRaw('contact_id, sum('.($deductible ? 'debit' : 'credit').') v')->pluck('v', 'contact_id');
        foreach ($gross as $cid => $v) {
            if (abs($v) >= 1) {
                $rows[] = [...$this->party((int) $cid), round($v, 0), 0];
            }
        }

        return [[...self::PARTY, $deductible ? 'Impuesto descontable' : 'Impuesto generado', $deductible ? 'IVA resultante por devoluciones en ventas anuladas, rescindidas o resueltas' : 'IVA recuperado en devoluciones en compras anuladas, rescindidas o resueltas'], $rows];
    }

    private function f1007(string $from, string $to): array
    {
        $rows = [];
        foreach ($this->movements($from, $to, ['4']) as $m) {
            if (! $m->contact_id) {
                continue;
            }
            $r = &$rows[$m->contact_id];
            $r ??= [0, 0];
            if (str_starts_with($m->account_code, '4175')) {
                $r[1] += (float) $m->d - (float) $m->c;
            } else {
                $r[0] += (float) $m->c - (float) $m->d;
            }
            unset($r);
        }
        $out = [];
        foreach ($rows as $cid => [$income, $returns]) {
            if (abs($income) + abs($returns) >= 1) {
                $out[] = ['4001', ...$this->party((int) $cid), round($income, 0), round($returns, 0)];
            }
        }

        return [['Concepto', ...self::PARTY, 'Ingresos brutos recibidos', 'Devoluciones, rebajas y descuentos'], $this->minor($this->sorted($out), '1007', [13, 14])];
    }

    private function balances(string $to, string $prefix, string $format, bool $debit): array
    {
        $map = config("exogena.$format");
        $rows = [];
        $q = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->where('date', '<=', $to)->where('account_code', 'like', "$prefix%")
            ->groupBy('account_code', 'contact_id')->selectRaw('account_code, contact_id, sum(debit) d, sum(credit) c')->get();
        foreach ($q as $m) {
            $concept = $this->concept($m->account_code, $map);
            if ($concept && $m->contact_id) {
                $k = $concept.'|'.$m->contact_id;
                $rows[$k] = ($rows[$k] ?? 0) + ($debit ? (float) $m->d - (float) $m->c : (float) $m->c - (float) $m->d);
            }
        }
        $out = [];
        foreach ($rows as $k => $v) {
            if ($v >= 1) {
                [$concept, $cid] = explode('|', $k);
                $out[] = [$concept, ...$this->party((int) $cid), round($v, 0)];
            }
        }

        return [['Concepto', ...self::PARTY, 'Saldo '.($debit ? 'cuentas por cobrar' : 'cuentas por pagar').' al 31-12'], $this->minor($this->sorted($out), $format, [13])];
    }

    private function movements(string $from, string $to, array $prefixes)
    {
        return DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])
            ->where(fn ($q) => collect($prefixes)->each(fn ($p) => $q->orWhere('account_code', 'like', "$p%")))
            ->where('document', 'not like', 'CC-998-%')
            ->groupBy('account_code', 'contact_id')->selectRaw('account_code, contact_id, sum(debit) d, sum(credit) c')->get();
    }

    /** Neto por tercero de un prefijo: credito-debito (o debito-credito con $debit). */
    private function byContact(string $from, string $to, string $prefix, bool $debit): array
    {
        return DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])->where('account_code', 'like', "$prefix%")
            ->whereNotNull('contact_id')->groupBy('contact_id')
            ->selectRaw('contact_id, '.($debit ? 'sum(debit)-sum(credit)' : 'sum(credit)-sum(debit)').' v')->pluck('v', 'contact_id')
            ->map(fn ($v) => (float) $v)->filter(fn ($v) => $v >= 1)->all();
    }

    /** Base por tercero: en los documentos con movimiento en $taxPrefix, suma de las cuentas base ($basePrefixes). */
    public function documentBase(string $from, string $to, string $taxPrefix, array $basePrefixes, bool $credit): array
    {
        $docs = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereBetween('date', [$from, $to])->where('account_code', 'like', "$taxPrefix%")
            ->whereNotNull('contact_id')->select('document', 'contact_id')->distinct()->get();
        $out = [];
        foreach ($docs->chunk(500) as $chunk) {
            $sums = DB::table('ledger_lines')->where('company_id', CurrentCompany::id())->whereIn('document', $chunk->pluck('document'))
                ->where(fn ($q) => collect($basePrefixes)->each(fn ($p) => $q->orWhere('account_code', 'like', "$p%")))
                ->groupBy('document')->selectRaw('document, '.($credit ? 'sum(credit)-sum(debit)' : 'sum(debit)-sum(credit)').' v')->pluck('v', 'document');
            foreach ($chunk as $d) {
                $out[$d->contact_id] = ($out[$d->contact_id] ?? 0) + (float) ($sums[$d->document] ?? 0);
            }
        }

        return $out;
    }

    private function concept(string $code, array $map): ?string
    {
        for ($len = strlen($code); $len > 0; $len--) {
            if (isset($map[substr($code, 0, $len)])) {
                return $map[substr($code, 0, $len)];
            }
        }

        return null;
    }

    public function party(int $id): array
    {
        $c = $this->contacts[$id] ??= DB::table('contacts')->where('id', $id)->first();
        if (! $c) {
            return array_fill(0, count(self::PARTY), '');
        }
        $legal = $c->person_type !== 'natural' && $c->person_type !== 'Person';
        $last = preg_split('/\s+/', trim((string) $c->last_name), 2) + ['', ''];
        $first = preg_split('/\s+/', trim((string) $c->first_name), 2) + ['', ''];

        return [
            $c->identification_type, $c->identification, $c->dv ?? '',
            $legal ? '' : $last[0], $legal ? '' : $last[1], $legal ? '' : $first[0], $legal ? '' : $first[1],
            $legal ? ($c->business_name ?: $c->name) : '', $c->address ?? '', $c->department_code ?? '', substr((string) $c->municipality_code, -3), $c->country_code === 'CO' ? '169' : $c->country_code,
        ];
    }

    /** Agrupa en el tercero 222222222 (cuantias menores) los que no superan el tope del formato. */
    private function minor(array $rows, string $format, array $valueCols): array
    {
        $limit = config("exogena.minor.$format");
        if (! $limit) {
            return $rows;
        }
        $keep = [];
        $minor = [];
        foreach ($rows as $r) {
            $total = array_sum(array_map(fn ($i) => abs((float) $r[$i]), $valueCols));
            if ($total >= $limit) {
                $keep[] = $r;

                continue;
            }
            $m = &$minor[$r[0]];
            $m ??= [$r[0], '43', '222222222', '', '', '', '', '', 'CUANTÍAS MENORES', '', '', '', '169', ...array_fill(0, count($r) - 13, 0)];
            foreach ($valueCols as $i) {
                $m[$i] += (float) $r[$i];
            }
            unset($m);
        }

        return [...$keep, ...array_values($minor)];
    }

    private function sorted(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$a[0], $a[2]] <=> [$b[0], $b[2]]);

        return $rows;
    }
}
