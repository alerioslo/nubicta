<?php

namespace App\Services\Siigo;

use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

/**
 * El listado de la API de Siigo entrega las lineas de cada comprobante en un orden arbitrario; el detalle
 * (GET /v1/invoices/{id}) las entrega en el orden en que Siigo las muestra e imprime. Aqui se reordenan las
 * lineas importadas segun el detalle.
 */
class LineOrder
{
    public const ENDPOINTS = ['FV' => 'v1/invoices', 'NC' => 'v1/credit-notes', 'CT' => 'v1/quotations'];

    public function __construct(private readonly SiigoClient $siigo) {}

    public static function make(): ?self
    {
        try {
            return new self(SiigoClient::fromConfig());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function pending(SalesDocument $doc): bool
    {
        return $doc->source === 'siigo' && (int) $doc->lines_order === 0 && $doc->siigo_id && isset(self::ENDPOINTS[$doc->type]);
    }

    /** @return bool|null true si se confirmo o corrigio el orden, false si el detalle no coincide, null si Siigo no respondio. */
    public function fix(SalesDocument $doc, bool $quick = true): ?bool
    {
        $path = self::ENDPOINTS[$doc->type].'/'.$doc->siigo_id;
        $detail = $quick ? $this->siigo->getOnce($path) : $this->siigo->get($path);
        if (! $detail) {
            return null;
        }

        $items = $doc->items()->reorder('position')->get(['id', 'code', 'description', 'quantity', 'position']);
        $order = [];
        foreach ($detail['items'] ?? [] as $line) {
            $match = $items->first(fn ($i) => ! in_array($i->id, $order, true)
                && (string) $i->code === trim((string) ($line['code'] ?? ''))
                && abs((float) $i->quantity - (float) ($line['quantity'] ?? 0)) < 0.0001
                && $i->description === mb_substr((string) ($line['description'] ?? $line['code'] ?? ''), 0, 500));
            if (! $match) {
                break;
            }
            $order[] = $match->id;
        }

        $ok = count($order) === $items->count();
        DB::transaction(function () use ($doc, $order, $ok) {
            if ($ok) {
                foreach ($order as $pos => $id) {
                    DB::table('sales_document_items')->where('id', $id)->update(['position' => $pos]);
                }
            }
            DB::table('sales_documents')->where('id', $doc->id)->update(['lines_order' => $ok ? 1 : 2]);
        });
        $doc->lines_order = $ok ? 1 : 2;

        return $ok;
    }
}
