<?php

namespace App\Services\Api;

use App\Jobs\DeliverWebhook;
use App\Models\ApiWebhook;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Models\WarehouseStock;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Suscripciones de la API: publica eventos del ERP a las URL registradas en /api/v1/webhooks. */
class Webhooks
{
    public const EVENTS = [
        'tercero.creado' => 'Se creó un cliente, proveedor o empleado.',
        'tercero.actualizado' => 'Cambiaron los datos de un tercero.',
        'producto.creado' => 'Se creó un producto o servicio.',
        'producto.actualizado' => 'Cambiaron los datos o precios de un producto.',
        'existencias.actualizadas' => 'Cambió la cantidad de un producto en una bodega.',
        'factura.creada' => 'Se elaboró una factura de venta.',
        'factura.anulada' => 'Se anuló una factura de venta.',
        'factura.dian' => 'Cambió el estado DIAN de una factura (aceptada, rechazada...).',
        'nota_credito.creada' => 'Se elaboró una nota crédito.',
        'nota_debito.creada' => 'Se elaboró una nota débito.',
        'cotizacion.creada' => 'Se elaboró una cotización.',
        'compra.creada' => 'Se registró una factura de compra.',
        'documento_soporte.creado' => 'Se elaboró un documento soporte.',
        'recibo_caja.creado' => 'Se registró un recibo de caja (pago de un cliente).',
        'recibo_pago.creado' => 'Se registró un recibo de pago o egreso.',
        'comprobante.creado' => 'Se elaboró un comprobante contable.',
    ];

    /** @var array<int, Collection>|null */
    private ?array $hooks = null;

    /** Con cola "sync" los envíos se hacen al terminar la respuesta, una vez por evento y registro. */
    private array $deferred = [];

    public static function boot(): void
    {
        Contact::created(fn ($m) => app(self::class)->fire('tercero.creado', $m));
        Contact::updated(fn ($m) => app(self::class)->fire('tercero.actualizado', $m));
        Product::created(fn ($m) => app(self::class)->fire('producto.creado', $m));
        Product::updated(fn ($m) => app(self::class)->fire('producto.actualizado', $m));
        WarehouseStock::updated(fn ($m) => app(self::class)->fire('existencias.actualizadas', $m));
        SalesDocument::created(fn ($m) => app(self::class)->fire(match ($m->type) {
            'FV' => 'factura.creada', 'NC' => 'nota_credito.creada', 'ND' => 'nota_debito.creada', 'CT' => 'cotizacion.creada', default => null,
        }, $m));
        SalesDocument::updated(function ($m) {
            if ($m->type === 'FV' && $m->wasChanged('status') && $m->status === 'annulled') {
                app(self::class)->fire('factura.anulada', $m);
            }
            if ($m->type === 'FV' && $m->wasChanged('dian_status') && in_array($m->dian_status, ['Accepted', 'Rejected'], true)) {
                app(self::class)->fire('factura.dian', $m);
            }
        });
        PurchaseDocument::created(fn ($m) => app(self::class)->fire(['FC' => 'compra.creada', 'DS' => 'documento_soporte.creado'][$m->type] ?? null, $m));
        Receipt::created(fn ($m) => app(self::class)->fire(['RC' => 'recibo_caja.creado', 'RP' => 'recibo_pago.creado'][$m->type] ?? null, $m));
        JournalEntry::created(fn ($m) => $m->source_type === null ? app(self::class)->fire('comprobante.creado', $m) : null);
    }

    public function fire(?string $event, Model $model): void
    {
        if (! $event || ($model->source ?? 'erp') !== 'erp' || ! ($company = (int) ($model->company_id ?? CurrentCompany::id()))) {
            return;
        }
        foreach ($this->subscribed($company) as $hook) {
            if (! in_array($event, $hook->events, true) && ! in_array('*', $hook->events, true)) {
                continue;
            }
            $job = new DeliverWebhook($hook->id, $company, $event, $model::class, $model->getKey());
            if (config('queue.default') === 'sync') {
                if (! isset($this->deferred[$job->uniqueId()])) {
                    $this->deferred[$job->uniqueId()] = true;
                    app()->terminating(fn () => rescue(fn () => $job->handle()));
                }
            } else {
                dispatch($job)->afterCommit();
            }
        }
    }

    private function subscribed(int $company)
    {
        if (! isset($this->hooks[$company])) {
            $this->hooks[$company] = Schema::hasTable('api_webhooks')
                ? ApiWebhook::query()->withoutGlobalScopes()->where('company_id', $company)->where('active', true)->get(['id', 'events'])
                : collect();
        }

        return $this->hooks[$company];
    }

    /** Rechaza URL locales o de red privada para que un webhook no pueda apuntar al propio servidor. */
    public static function safeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return 'La URL debe empezar por https://';
        }
        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return "No se pudo resolver el dominio {$host}.";
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'La URL no puede apuntar a una red privada o local.';
            }
        }

        return null;
    }
}
