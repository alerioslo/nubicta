<?php

namespace App\Services\Siigo\Importers;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Seller;
use App\Models\Tax;
use App\Models\Warehouse;
use Illuminate\Support\Str;

/** Importa tablas de configuracion: impuestos, tipos de documento, formas de pago, etc. */
class ParametersImporter extends BaseImporter
{
    /** Tipos de Siigo; C (cotizacion) y DF (debito facturacion) se guardan como CT y ND. */
    private const DOCUMENT_TYPES = ['FV', 'NC', 'RC', 'CC', 'FC', 'C', 'DF'];

    private const LOCAL_TYPES = ['C' => 'CT', 'DF' => 'ND'];

    private const PAYMENT_DOC_TYPES = ['FV', 'FC', 'RC'];

    public function entity(): string
    {
        return 'parametros';
    }

    protected function handle(array $params): void
    {
        $this->taxes();
        $this->documentTypes();
        $this->paymentMethods();
        $this->simple('cost-centers', 'cost_center', CostCenter::class, fn ($r) => [
            'code' => (string) ($r['code'] ?? $r['id']), 'name' => $r['name'], 'active' => $r['active'] ?? true,
        ]);
        $this->simple('warehouses', 'warehouse', Warehouse::class, fn ($r) => [
            'name' => $r['name'], 'active' => $r['active'] ?? true,
        ]);
        $this->simple('price-lists', 'price_list', PriceList::class, fn ($r) => [
            'position' => $r['position'], 'name' => $r['name'], 'active' => $r['active'] ?? true,
        ]);
        $this->simple('account-groups', 'account_group', AccountGroup::class, fn ($r) => [
            'name' => $r['name'], 'active' => $r['active'] ?? true,
        ]);
        $this->sellers();
        $this->ensureDefaultWarehouse();
    }

    private function taxes(): void
    {
        $this->log('Impuestos...');
        foreach ($this->siigo->get('v1/taxes') as $r) {
            $this->upsert(Tax::class, 'tax', $r, fn ($r) => [
                'siigo_id' => (string) $r['id'],
                'name' => $r['name'],
                'type' => $r['type'],
                'percentage' => $r['percentage'] ?? 0,
                'fixed_value' => str_contains(Str::lower($r['name']), 'por valor'),
                'dian_code' => Tax::TYPES[$r['type']] ?? null,
                'active' => $r['active'] ?? true,
                ...$this->taxAccounts($r),
            ]);
        }
    }

    /** Prefijos PUC por tipo de impuesto: [ventas, compras]. */
    private const TAX_PREFIXES = [
        'IVA' => ['2408', '2408'],
        'Impoconsumo' => ['2495', '2495'],
        'Retefuente' => ['135515', '2365'],
        'ReteIVA' => ['135517', '2367'],
        'ReteICA' => ['135518', '2368'],
        'Autorretencion' => ['135519', '2369'],
    ];

    /**
     * Asocia cuentas del PUC por relacion, prefijo y porcentaje
     * (ej. IVA 19% -> 240805xx "IVA generado 19%"). El contador puede corregirlas en Configuracion > Impuestos.
     */
    private function taxAccounts(array $r): array
    {
        [$salesPrefix, $purchasePrefix] = self::TAX_PREFIXES[$r['type']] ?? [null, null];
        $pct = rtrim(rtrim(number_format((float) ($r['percentage'] ?? 0), 2, '.', ''), '0'), '.');

        $find = function (string $relation, ?string $prefix) use ($pct) {
            if ($prefix === null) {
                return null;
            }
            $q = Account::withoutGlobalScopes()->where('company_id', $this->companyId)
                ->where('transactional', true)->where('code', 'like', "$prefix%");
            $byRelation = (clone $q)->where('relation', $relation);

            foreach ([$byRelation, $q] as $base) {
                $id = (clone $base)->where(fn ($w) => $w->where('name', 'like', "%{$pct}%")
                    ->orWhere('name', 'like', '%'.str_replace('.', ',', $pct).'%'))->orderBy('code')->value('id');
                if ($id) {
                    return $id;
                }
            }

            return $byRelation->orderBy('code')->value('id');
        };

        return [
            'sales_account_id' => $find('Impuestos - Ventas', $salesPrefix),
            'purchases_account_id' => $find('Impuestos - Compras', $purchasePrefix),
            'sales_return_account_id' => $find('Impuestos - Devolución ventas', $salesPrefix),
            'purchases_return_account_id' => $find('Impuestos - Devolución compras', $purchasePrefix),
        ];
    }

    private function documentTypes(): void
    {
        $this->log('Tipos de documento...');
        foreach (self::DOCUMENT_TYPES as $type) {
            foreach ($this->siigo->get('v1/document-types', ['type' => $type]) as $r) {
                $this->upsert(DocumentType::class, 'document_type', $r, fn ($r) => [
                    'siigo_id' => (string) $r['id'],
                    'type' => self::LOCAL_TYPES[$r['type']] ?? $r['type'],
                    'code' => (string) $r['code'],
                    'name' => $r['name'],
                    'description' => $r['description'] ?? null,
                    'consecutive' => $r['consecutive'] ?? 1,
                    'automatic_number' => $r['automatic_number'] ?? true,
                    'electronic_type' => $r['electronic_type'] ?? 'NoElectronic',
                    'cost_center' => $r['cost_center'] ?? false,
                    'cost_center_mandatory' => $r['cost_center_mandatory'] ?? false,
                    'seller_by_item' => $r['seller_by_item'] ?? false,
                    'decimals' => $r['decimals'] ?? true,
                    'discount_type' => $r['discount_type'] ?? 'Percentage',
                    'reteiva' => $r['reteiva'] ?? false,
                    'reteica' => $r['reteica'] ?? false,
                    'self_withholding' => $r['self_withholding'] ?? false,
                    'active' => $r['active'] ?? true,
                ]);
            }
            usleep(500_000);
        }
    }

    private function paymentMethods(): void
    {
        $this->log('Formas de pago...');
        $docTypes = [];
        $rows = [];
        foreach (self::PAYMENT_DOC_TYPES as $doc) {
            foreach ($this->siigo->get('v1/payment-types', ['document_type' => $doc]) as $r) {
                $rows[$r['id']] = $r;
                $docTypes[$r['id']][] = $doc;
            }
            usleep(500_000);
        }
        foreach ($rows as $id => $r) {
            $r['document_types'] = $docTypes[$id];
            $this->upsert(PaymentMethod::class, 'payment_method', $r, fn ($r) => [
                'siigo_id' => (string) $r['id'],
                'name' => $r['name'],
                'type' => $r['type'],
                'means_code' => $this->guessMeans($r['name'], $r['type']),
                'due_date' => $r['due_date'] ?? false,
                'document_types' => $r['document_types'],
                'active' => $r['active'] ?? true,
            ]);
        }
    }

    private function guessMeans(string $name, string $type): string
    {
        $n = Str::lower(Str::ascii($name));

        return match (true) {
            $type === 'Cartera' => 'ZZZ',
            str_contains($n, 'efectivo') || str_contains($n, 'caja') => '10',
            str_contains($n, 'consignacion') => '42',
            str_contains($n, 'credito') && str_contains($n, 'tarjeta') => '48',
            str_contains($n, 'debito') || str_contains($n, 'datafono') => '49',
            str_contains($n, 'cheque') => '20',
            default => '47',
        };
    }

    private function sellers(): void
    {
        $this->log('Vendedores (usuarios de Siigo)...');
        foreach ($this->siigo->paginate('v1/users') as $r) {
            $this->upsert(Seller::class, 'seller', $r, fn ($r) => [
                'siigo_id' => (string) $r['id'],
                'identification' => $r['identification'] ?? null,
                'name' => Str::title(Str::lower(trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')))),
                'email' => $r['email'] ?? null,
                'active' => $r['active'] ?? true,
            ]);
        }
    }

    private function simple(string $endpoint, string $entity, string $model, \Closure $map): void
    {
        $this->log("$endpoint...");
        foreach ($this->siigo->get("v1/$endpoint") as $r) {
            $this->upsert($model, $entity, $r, fn ($r) => ['siigo_id' => (string) $r['id'], ...$map($r)]);
        }
        usleep(500_000);
    }

    private function ensureDefaultWarehouse(): void
    {
        $exists = Warehouse::withoutGlobalScopes()->where('company_id', $this->companyId)->exists();
        if (! $exists) {
            Warehouse::create(['company_id' => $this->companyId, 'siigo_id' => '-1', 'name' => 'Principal', 'is_default' => true]);
        }
    }
}
