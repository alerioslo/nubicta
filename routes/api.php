<?php

use App\Http\Controllers\ApiV1\CatalogController;
use App\Http\Controllers\ApiV1\ContactApiController;
use App\Http\Controllers\ApiV1\DocsController;
use App\Http\Controllers\ApiV1\ProductApiController;
use App\Http\Controllers\ApiV1\PurchaseApiController;
use App\Http\Controllers\ApiV1\ReceiptApiController;
use App\Http\Controllers\ApiV1\ReportApiController;
use App\Http\Controllers\ApiV1\SalesApiController;
use App\Http\Controllers\ApiV1\WebhookApiController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::get('/openapi.json', [DocsController::class, 'spec']);
Route::get('/docs', [DocsController::class, 'page']);

Route::middleware([AuthenticateApiToken::class, 'throttle:120,1'])->group(function () {
    Route::controller(CatalogController::class)->group(function () {
        Route::get('/tipos-documento', 'documentTypes');
        Route::get('/formas-pago', 'paymentMethods');
        Route::get('/impuestos', 'taxes');
        Route::get('/listas-precios', 'priceLists');
        Route::get('/bodegas', 'warehouses');
        Route::get('/usuarios', 'users');
        Route::get('/vendedores', 'sellers');
        Route::get('/centros-costo', 'costCenters');
        Route::get('/grupos-inventario', 'accountGroups');
        Route::get('/activos-fijos', 'fixedAssets');
        Route::get('/cuentas', 'accounts');
        Route::get('/ciudades', 'cities');
        Route::get('/conceptos', 'concepts');
        Route::get('/cuentas-bancarias', 'bankAccounts');
    });

    Route::apiResource('terceros', ContactApiController::class)->parameters(['terceros' => 'id'])->whereNumber('id');
    Route::apiResource('productos', ProductApiController::class)->parameters(['productos' => 'id'])->whereNumber('id');

    $documents = function (string $controller, array $kinds, array $routes) {
        foreach (array_keys($kinds) as $clase) {
            Route::controller($controller)->prefix($clase)->group(function () use ($clase, $routes) {
                foreach ($routes as [$method, $uri, $action]) {
                    Route::{$method}($uri, $action)->defaults('clase', $clase)->whereNumber('id');
                }
            });
        }
    };
    $documents(SalesApiController::class, SalesApiController::KINDS, [
        ['get', '/', 'index'], ['post', '/', 'store'], ['get', '/{id}', 'show'], ['put', '/{id}', 'update'], ['delete', '/{id}', 'destroy'],
        ['post', '/{id}/anular', 'annul'], ['get', '/{id}/pdf', 'pdf'], ['get', '/{id}/xml', 'xml'], ['post', '/{id}/correo', 'mail'],
        ['get', '/{id}/dian', 'dian'], ['post', '/{id}/dian', 'sendDian'],
    ]);
    $documents(PurchaseApiController::class, PurchaseApiController::KINDS, [
        ['get', '/', 'index'], ['post', '/', 'store'], ['get', '/{id}', 'show'], ['put', '/{id}', 'update'],
        ['post', '/{id}/anular', 'annul'], ['get', '/{id}/xml', 'xml'], ['post', '/{id}/dian', 'sendDian'],
    ]);
    $documents(ReceiptApiController::class, ReceiptApiController::KINDS, [
        ['get', '/', 'index'], ['post', '/', 'store'], ['get', '/{id}', 'show'], ['delete', '/{id}', 'destroy'],
        ['post', '/{id}/anular', 'annul'], ['get', '/{id}/pdf', 'pdf'], ['post', '/{id}/correo', 'mail'],
    ]);

    Route::controller(ReceiptApiController::class)->group(function () {
        Route::get('/comprobantes', 'journals');
        Route::post('/comprobantes', 'storeJournal');
        Route::get('/comprobantes/{id}', 'journal')->whereNumber('id');
        Route::delete('/comprobantes/{id}', 'destroyJournal')->whereNumber('id');
        Route::post('/comprobantes/{id}/anular', 'annulJournal')->whereNumber('id');
    });

    Route::controller(ReportApiController::class)->group(function () {
        Route::get('/reportes/balance-prueba', 'trialBalance');
        Route::get('/reportes/balance-prueba-terceros', 'trialBalanceByContact');
        Route::get('/cuentas-por-cobrar', 'receivables');
        Route::get('/cuentas-por-pagar', 'payables');
        Route::get('/existencias', 'stock');
    });

    Route::controller(WebhookApiController::class)->group(function () {
        Route::get('/webhooks/eventos', 'events');
        Route::get('/webhooks', 'index');
        Route::post('/webhooks', 'store');
        Route::get('/webhooks/{id}', 'show')->whereNumber('id');
        Route::put('/webhooks/{id}', 'update')->whereNumber('id');
        Route::delete('/webhooks/{id}', 'destroy')->whereNumber('id');
        Route::post('/webhooks/{id}/probar', 'test')->whereNumber('id');
    });
});
