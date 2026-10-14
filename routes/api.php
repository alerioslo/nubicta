<?php

use App\Http\Controllers\ApiV1\ApiController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateApiToken::class, 'throttle:120,1'])->group(function () {
    Route::get('/terceros', [ApiController::class, 'contacts']);
    Route::post('/terceros', [ApiController::class, 'storeContact']);
    Route::get('/terceros/{id}', [ApiController::class, 'showContact'])->whereNumber('id');
    Route::get('/productos', [ApiController::class, 'products']);
    Route::get('/productos/{id}', [ApiController::class, 'showProduct'])->whereNumber('id');
    Route::get('/facturas', [ApiController::class, 'invoices']);
    Route::get('/facturas/{id}', [ApiController::class, 'showInvoice'])->whereNumber('id');
});
