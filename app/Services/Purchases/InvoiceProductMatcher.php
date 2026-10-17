<?php

namespace App\Services\Purchases;

use App\Models\Contact;
use App\Models\DianInboxDocument;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\PurchaseDocumentItem;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Vincula las lineas de una factura recibida (Bandeja DIAN) con los productos del ERP:
 * 1) codigo / codigo de barras / referencia igual, 2) mismo proveedor y descripcion en compras anteriores,
 * 3) la IA elige entre los productos candidatos mas parecidos (solo si esta habilitada).
 */
class InvoiceProductMatcher
{
    private const CANDIDATES = 12;

    private const AI_BATCH = 40;

    private const STOPWORDS = ['con', 'sin', 'para', 'por', 'del', 'los', 'las', 'una', 'uno', 'und', 'unid', 'unidad', 'unidades', 'cja', 'caja', 'paq', 'x', 'de', 'la', 'el', 'en', 'y'];

    /**
     * Producto sugerido por linea de la factura (indice de la linea => sugerencia).
     *
     * @return array<int, array{product_id:int, label:string, source:string, confidence:?int, reason:?string}>
     */
    public function suggestions(DianInboxDocument $doc, array $data): array
    {
        $local = $this->local($data['lines'], (string) $data['supplier']['nit']);
        $ai = collect($doc->product_matches['lines'] ?? [])->filter(fn ($m) => ! empty($m['product_id']))->all();
        $merged = $local + $ai;
        $products = Product::query()->whereIn('id', collect($merged)->pluck('product_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return collect($merged)->filter(fn ($m) => isset($products[$m['product_id']]))
            ->map(fn ($m) => [...$m, 'label' => "{$products[$m['product_id']]->code} - {$products[$m['product_id']]->name}"])
            ->sortKeys()->all();
    }

    /**
     * Pide a la IA el producto de las lineas que no se vincularon por codigo ni por historial y guarda el resultado.
     *
     * @return array{matched:int, pending:int}
     */
    public function runAi(DianInboxDocument $doc, array $data): array
    {
        $ai = AiClient::forCurrentCompany();
        if (! $ai->enabled()) {
            throw new RuntimeException('Activa y configura la IA en Configuración > General > Inteligencia artificial.');
        }
        $local = $this->local($data['lines'], (string) $data['supplier']['nit']);
        $pending = collect($data['lines'])->except(array_keys($local))
            ->map(fn ($l) => ['line' => $l, 'candidates' => $this->candidates($l)])
            ->filter(fn ($p) => $p['candidates']->isNotEmpty());

        $found = [];
        foreach ($pending->chunk(self::AI_BATCH, true) as $batch) {
            $found += $this->ask($ai, $batch, (string) $data['supplier']['name']);
        }
        $doc->update(['product_matches' => ['at' => now()->toDateTimeString(), 'model' => $ai->model(), 'lines' => $found]]);

        return ['matched' => count($found), 'pending' => count($data['lines']) - count($local) - count($found)];
    }

    /** @return array<int, array{product_id:int, source:string, confidence:?int, reason:?string}> */
    private function local(array $lines, string $supplierNit): array
    {
        $out = [];
        $codes = collect($lines)->pluck('code')->filter()->unique()->values();
        $byCode = $codes->isEmpty() ? collect() : Product::query()->where('active', true)
            ->where(fn ($q) => $q->whereIn('code', $codes)->orWhereIn('barcode', $codes)->orWhereIn('reference', $codes))
            ->get(['id', 'code', 'barcode', 'reference']);
        foreach ($lines as $i => $l) {
            if ($l['code'] === '') {
                continue;
            }
            $p = $byCode->firstWhere('code', $l['code']) ?? $byCode->firstWhere('barcode', $l['code']) ?? $byCode->firstWhere('reference', $l['code']);
            if ($p) {
                $out[$i] = ['product_id' => $p->id, 'source' => 'Código', 'confidence' => 100, 'reason' => null];
            }
        }

        $contacts = Contact::query()->where('identification', preg_replace('/\D/', '', $supplierNit))->pluck('id');
        $missing = collect($lines)->except(array_keys($out))->filter(fn ($l) => $l['description'] !== '');
        if ($contacts->isNotEmpty() && $missing->isNotEmpty()) {
            $history = PurchaseDocumentItem::query()
                ->whereIn('purchase_document_id', PurchaseDocument::query()->whereIn('contact_id', $contacts)->select('id'))
                ->whereNotNull('product_id')->whereIn('description', $missing->pluck('description')->unique())
                ->whereIn('product_id', Product::query()->where('active', true)->select('id'))
                ->orderByDesc('id')->get(['description', 'product_id']);
            foreach ($missing as $i => $l) {
                $h = $history->first(fn ($r) => mb_strtolower($r->description) === mb_strtolower($l['description']));
                if ($h) {
                    $out[$i] = ['product_id' => $h->product_id, 'source' => 'Historial', 'confidence' => 100, 'reason' => 'Mismo producto en compras anteriores a este proveedor'];
                }
            }
        }

        return $out;
    }

    /** Productos activos con mas palabras en comun con la descripcion de la linea. */
    private function candidates(array $line): Collection
    {
        $words = $this->words($line['description'].' '.$line['code']);
        if ($words === []) {
            return collect();
        }
        $rows = Product::query()->where('active', true)
            ->where(fn ($q) => collect($words)->each(fn ($w) => $q->orWhere('name', 'like', "%$w%")->orWhere('reference', 'like', "%$w%")))
            ->limit(400)->get(['id', 'code', 'name', 'reference', 'brand', 'unit_label']);

        return $rows->map(function (Product $p) use ($words) {
            $own = $this->words("{$p->name} {$p->reference} {$p->brand}");
            $p->score = count(array_intersect($words, $own)) * 2 + count(array_filter($words, fn ($w) => str_contains(Str::ascii(mb_strtolower("{$p->name} {$p->reference}")), $w)));

            return $p;
        })->sortByDesc('score')->take(self::CANDIDATES)->values();
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($parts, fn ($w) => (strlen($w) >= 3 || preg_match('/\d/', $w)) && ! in_array($w, self::STOPWORDS, true))));
    }

    private function ask(AiClient $ai, Collection $batch, string $supplier): array
    {
        $payload = $batch->map(fn ($p, $i) => [
            'line' => $i, 'code' => $p['line']['code'], 'description' => $p['line']['description'],
            'candidates' => $p['candidates']->map(fn ($c) => array_filter(['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'reference' => $c->reference, 'brand' => $c->brand, 'unit' => $c->unit_label]))->all(),
        ])->values()->all();

        $data = $ai->json([
            ['role' => 'system', 'content' => 'Eres un auxiliar contable colombiano que causa facturas de compra. Para cada línea de la factura del proveedor '
                .'elige, SOLO entre los candidatos de esa misma línea, el producto del inventario que es el mismo artículo (mismo producto, presentación y tamaño). '
                .'Si ninguno es claramente el mismo artículo usa product_id null; no adivines. '
                .'Responde únicamente con JSON: {"matches":[{"line":<número>,"product_id":<id o null>,"confidence":<0 a 100>,"reason":"<máximo 15 palabras>"}]}'],
            ['role' => 'user', 'content' => "Proveedor: $supplier\nLíneas:\n".json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ]);

        $out = [];
        foreach ((array) ($data['matches'] ?? []) as $m) {
            $line = $m['line'] ?? null;
            $id = (int) ($m['product_id'] ?? 0);
            $confidence = (int) ($m['confidence'] ?? 0);
            if (! is_numeric($line) || ! $batch->has((int) $line) || ! $id || $confidence < $ai->minConfidence()) {
                continue;
            }
            if ($batch[(int) $line]['candidates']->contains('id', $id)) {
                $out[(int) $line] = ['product_id' => $id, 'source' => 'IA', 'confidence' => $confidence, 'reason' => mb_substr((string) ($m['reason'] ?? ''), 0, 200)];
            }
        }

        return $out;
    }
}
