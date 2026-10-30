<?php

namespace Tests\Unit;

use App\Models\Tax;
use App\Services\Accounting\AccountResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountResolverTest extends TestCase
{
    public function test_tax_return_resolves_the_sales_or_purchase_return_account(): void
    {
        $tax = new Tax([
            'name' => 'IVA 19%',
            'sales_return_account_id' => 101,
            'purchases_return_account_id' => 202,
        ]);
        $resolver = new AccountResolver;

        $this->assertSame(101, $resolver->taxReturn($tax, false));
        $this->assertSame(202, $resolver->taxReturn($tax, true));
    }

    public function test_tax_return_rejects_a_missing_return_account_with_a_clear_error(): void
    {
        $tax = new Tax(['name' => 'IVA 19%']);

        try {
            (new AccountResolver)->taxReturn($tax, false);
            $this->fail('Se esperaba rechazar la contabilización sin cuenta de devolución.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['cuentas' => ['No se puede contabilizar la devolución: el impuesto IVA 19% no tiene configurada la cuenta de devolución en ventas. Configúrala en Configuración > Contabilidad > Impuestos.']],
                $exception->errors(),
            );
        }
    }
}
