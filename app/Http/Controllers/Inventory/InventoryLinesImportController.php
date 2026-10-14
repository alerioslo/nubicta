<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Carga por Excel de las lineas del conteo fisico y del ajuste de inventario (Importar conteo fisico / comprobantes de ajuste). */
class InventoryLinesImportController extends Controller
{
    private const HEAD = [
        'conteo' => ['Código producto', 'Existencias contadas'],
        'ajuste' => ['Código producto', 'Aumenta / Disminuye', 'Cantidad', 'Costo unitario', 'Cuenta contable Ingreso/Gasto'],
    ];

    private const MAX = 500;

    public function template(string $kind)
    {
        $p = Product::query()->where('stock_control', true)->where('active', true)->first();
        $sample = $kind === 'conteo' ? [$p?->code ?? '1001', 10] : [$p?->code ?? '1001', 'Aumenta', 5, (float) ($p?->average_cost ?? 1000),
            Account::query()->where('transactional', true)->where('code', 'like', '4295%')->value('code') ?? Account::query()->where('transactional', true)->where('code', 'like', '42%')->value('code')];

        return ArrayExport::download("plantilla_{$kind}_inventario.xlsx", self::HEAD[$kind], [$sample], $kind === 'conteo' ? 'Conteo físico' : 'Ajuste');
    }

    public function parse(Request $request, string $kind): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:xlsx,xls,csv']],
            ['file.required' => 'Selecciona el archivo de Excel', 'file.extensions' => 'El archivo debe ser Excel (xlsx/xls) o CSV']);
        $sheet = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        array_shift($sheet);
        $sheet = array_values(array_filter($sheet, fn ($r) => array_filter($r, fn ($v) => trim((string) $v) !== '')));
        if (! $sheet || count($sheet) > self::MAX) {
            return response()->json(['message' => $sheet ? 'Máximo '.self::MAX.' registros por archivo' : 'El archivo no tiene registros'], 422);
        }
        $products = Product::query()->whereIn('code', array_map(fn ($r) => trim((string) $r[0]), $sheet))->get()->keyBy('code');
        $accounts = $kind === 'ajuste' ? Account::query()->whereIn('code', array_map(fn ($r) => trim((string) ($r[4] ?? '')), $sheet))->get()->keyBy('code') : collect();
        $rows = [];
        $errors = [];
        foreach ($sheet as $i => $r) {
            $line = $i + 2;
            $code = trim((string) $r[0]);
            $p = $products->get($code);
            if (! $p || ! $p->stock_control) {
                $errors[] = "Fila $line: ".($p ? "el producto $code no maneja inventario" : "no existe el producto $code");

                continue;
            }
            $row = ['product_id' => $p->id, 'label' => "{$p->code} - {$p->name}", 'description' => $p->name, 'reference' => $p->reference ?? '', 'stock' => (float) $p->stock];
            if ($kind === 'conteo') {
                if (! is_numeric($r[1] ?? null) || $r[1] < 0) {
                    $errors[] = "Fila $line: las existencias contadas deben ser un número mayor o igual a 0";

                    continue;
                }
                $rows[] = $row + ['counted' => (float) $r[1]];

                continue;
            }
            $dir = mb_strtolower(trim((string) ($r[1] ?? '')));
            $dir = str_starts_with($dir, 'a') || $dir === '+' ? '+' : (str_starts_with($dir, 'd') || $dir === '-' ? '-' : null);
            $account = $accounts->get(trim((string) ($r[4] ?? '')));
            $problems = array_filter([
                $dir ? null : 'indica Aumenta o Disminuye',
                is_numeric($r[2] ?? null) && $r[2] > 0 ? null : 'la cantidad debe ser mayor a 0',
                ($r[3] ?? '') === '' || is_numeric($r[3]) ? null : 'el costo unitario no es un número',
                trim((string) ($r[4] ?? '')) === '' || $account?->transactional ? null : 'la cuenta contable no existe o no es auxiliar',
            ]);
            if ($problems) {
                $errors[] = "Fila $line: ".implode(', ', $problems);

                continue;
            }
            $rows[] = $row + ['direction' => $dir, 'quantity' => (float) $r[2], 'cost' => ($r[3] ?? '') === '' ? (float) $p->average_cost : (float) $r[3],
                'account_id' => $account?->id, 'account_label' => $account ? "{$account->code} - {$account->name}" : ''];
        }

        return response()->json(['rows' => $rows, 'errors' => $errors]);
    }
}
