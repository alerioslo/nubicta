<?php

namespace App\Services\Accounting;

use App\Models\AccountGroup;
use App\Models\PaymentMethod;
use App\Models\Tax;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Resuelve la cuenta contable de cada parametro, con las cuentas por defecto de config/accounting.php. */
class AccountResolver
{
    /** @var array<string, int> */
    private array $byCode = [];

    /** @var array<int, string> */
    private array $codes = [];

    public function id(string $code): int
    {
        if (! isset($this->byCode[$code])) {
            $id = DB::table('accounts')->where('company_id', CurrentCompany::id())->where('code', $code)->value('id');
            if (! $id) {
                throw new RuntimeException("La cuenta contable $code no existe en el PUC de la empresa.");
            }
            $this->byCode[$code] = (int) $id;
            $this->codes[(int) $id] = $code;
        }

        return $this->byCode[$code];
    }

    public function code(int $id): string
    {
        if (! isset($this->codes[$id])) {
            $code = DB::table('accounts')->where('company_id', CurrentCompany::id())->where('id', $id)->value('code');
            if (! $code) {
                throw new RuntimeException("La cuenta contable #$id no existe.");
            }
            $this->codes[$id] = (string) $code;
            $this->byCode[(string) $code] = $id;
        }

        return $this->codes[$id];
    }

    public function default(string $key): int
    {
        return $this->id((string) config("accounting.defaults.$key"));
    }

    public function paymentMethod(PaymentMethod $method): int
    {
        return $method->account_id ?: $this->default(match ($method->type) {
            'Cartera' => 'customers',
            'Proveedor' => 'suppliers',
            default => 'cash',
        });
    }

    /** @param 'income'|'inventory'|'cost'|'return' $kind */
    public function group(?AccountGroup $group, string $kind): int
    {
        $id = $group?->{$kind.'_account_id'};

        return $id ?: $this->default(['income' => 'income', 'inventory' => 'inventory', 'cost' => 'cost', 'return' => 'sales_returns'][$kind]);
    }

    public function tax(Tax $tax, bool $purchases): int
    {
        $id = $purchases ? $tax->purchases_account_id : $tax->sales_account_id;
        if (! $id) {
            throw new RuntimeException("El impuesto {$tax->name} no tiene cuenta contable de ".($purchases ? 'compras' : 'ventas').'.');
        }

        return $id;
    }

    public function taxReturn(Tax $tax, bool $purchases): int
    {
        $id = $purchases ? $tax->purchases_return_account_id : $tax->sales_return_account_id;
        if (! $id) {
            $side = $purchases ? 'compras' : 'ventas';
            throw ValidationException::withMessages([
                'cuentas' => "No se puede contabilizar la devolución: el impuesto {$tax->name} no tiene configurada la cuenta de devolución en {$side}. Configúrala en Configuración > Contabilidad > Impuestos.",
            ]);
        }

        return $id;
    }
}
