<?php

namespace App\Services\Siigo\Importers;

use App\Models\AuditLog;
use App\Models\SiigoImportError;
use App\Models\SiigoImportRun;
use App\Services\Siigo\SiigoClient;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class BaseImporter
{
    protected SiigoImportRun $run;

    /** @var Closure(string):void|null */
    protected ?Closure $output = null;

    protected bool $force = false;

    public function force(bool $force = true): static
    {
        $this->force = $force;

        return $this;
    }

    public function __construct(protected SiigoClient $siigo, protected int $companyId) {}

    abstract public function entity(): string;

    abstract protected function handle(array $params): void;

    public function onOutput(Closure $fn): static
    {
        $this->output = $fn;

        return $this;
    }

    public function run(array $params = []): SiigoImportRun
    {
        $this->run = SiigoImportRun::create([
            'company_id' => $this->companyId,
            'entity' => $this->entity(),
            'params' => $params,
            'started_at' => now(),
        ]);

        $auditing = AuditLog::$enabled;
        AuditLog::$enabled = false;
        try {
            $this->handle($params);
            $this->run->update(['status' => 'done', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $this->run->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            throw $e;
        } finally {
            AuditLog::$enabled = $auditing;
        }

        return $this->run;
    }

    protected function log(string $msg): void
    {
        if ($this->output) {
            ($this->output)($msg);
        }
    }

    /**
     * Crea o actualiza el registro local vinculado a un siigo_id.
     * Si el JSON no cambio desde la ultima importacion se omite.
     *
     * @param  class-string<Model>  $modelClass
     * @param  Closure(array):array  $map  convierte el JSON de Siigo en atributos locales
     */
    protected function upsert(string $modelClass, string $entity, array $row, Closure $map, ?string $siigoId = null): ?Model
    {
        $siigoId ??= (string) $row['id'];
        $hash = sha1(json_encode($row));
        $this->run->increment('fetched');

        try {
            return DB::transaction(function () use ($modelClass, $entity, $row, $map, $siigoId, $hash) {
                $mapping = DB::table('siigo_mappings')
                    ->where(['company_id' => $this->companyId, 'entity' => $entity, 'siigo_id' => $siigoId])
                    ->first();

                if ($mapping && $mapping->hash === $hash && ! $this->force) {
                    $this->run->increment('skipped');

                    return null;
                }

                $attributes = ['company_id' => $this->companyId, ...$map($row)];
                $model = $mapping ? $modelClass::withoutGlobalScopes()->find($mapping->local_id) : null;
                if (array_key_exists('balance', $attributes) && in_array($modelClass, [\App\Models\SalesDocument::class, \App\Models\PurchaseDocument::class], true)) {
                    $siigo = round((float) $attributes['balance'], 2);
                    $attributes['balance'] = $model && $model->synced_balance !== null
                        ? round((float) $model->balance + $siigo - (float) $model->synced_balance, 2)
                        : $siigo;
                    $attributes['synced_balance'] = $siigo;
                }

                if ($model) {
                    $model->fill($attributes)->save();
                    $this->run->increment('updated');
                } else {
                    $model = $modelClass::create($attributes);
                    $this->run->increment('created');
                }

                DB::table('siigo_mappings')->updateOrInsert(
                    ['company_id' => $this->companyId, 'entity' => $entity, 'siigo_id' => $siigoId],
                    ['local_id' => $model->getKey(), 'hash' => $hash, 'updated_at' => now(), 'created_at' => $mapping->created_at ?? now()],
                );

                return $model;
            });
        } catch (Throwable $e) {
            $this->run->increment('failed');
            SiigoImportError::create([
                'run_id' => $this->run->id,
                'siigo_id' => $siigoId,
                'error' => mb_substr($e->getMessage(), 0, 3000),
                'payload' => $row,
            ]);
            $this->log("  error {$siigoId}: ".mb_substr($e->getMessage(), 0, 200));

            return null;
        }
    }

    /**
     * Valor del descuento de un item: la API lo entrega como {percentage, value} o como numero.
     * En compras el numero es un porcentaje cuando el documento trae discount_type = Percentage.
     */
    protected function discountValue(array $item, ?string $type = null): float
    {
        $d = $item['discount'] ?? 0;
        if (is_array($d)) {
            return (float) ($d['value'] ?? 0);
        }

        return $type === 'Percentage'
            ? round((float) $item['quantity'] * (float) $item['price'] * (float) $d / 100, 2)
            : (float) $d;
    }

    /** Id local de un registro ya importado. */
    protected function localId(string $entity, string|int|null $siigoId): ?int
    {
        if ($siigoId === null || $siigoId === '') {
            return null;
        }

        static $cache = [];
        $key = "$entity|$siigoId";

        return $cache[$key] ??= DB::table('siigo_mappings')
            ->where(['company_id' => $this->companyId, 'entity' => $entity, 'siigo_id' => (string) $siigoId])
            ->value('local_id');
    }
}
