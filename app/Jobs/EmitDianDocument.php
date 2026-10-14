<?php

namespace App\Jobs;

use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Services\Dian\DianService;
use App\Services\Dian\DianUnavailableException;
use App\Support\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;

/** Reintenta en cola la emision de un documento electronico cuando la DIAN no estuvo disponible. */
class EmitDianDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800, 3600];

    /** @param class-string<SalesDocument|PurchaseDocument> $model */
    public function __construct(public string $model, public int $id, public int $companyId) {}

    public static function for(SalesDocument|PurchaseDocument $doc): self
    {
        return new self($doc::class, $doc->id, $doc->company_id);
    }

    public function handle(): void
    {
        CurrentCompany::set($this->companyId);
        $doc = $this->model::query()->find($this->id);
        if (! $doc || $doc->dian_status === 'Accepted' || $doc->status !== 'issued') {
            return;
        }
        try {
            DianService::forCurrent()->emit($doc);
        } catch (DianUnavailableException|ConnectionException $e) {
            $doc->forceFill(['dian_status' => 'Pending', 'dian_message' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        } catch (\RuntimeException $e) {
            $doc->forceFill(['dian_status' => 'Draft', 'dian_message' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->fail($e);
        }
    }
}
