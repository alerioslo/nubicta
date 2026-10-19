<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\Controller;
use App\Support\Api\OpenApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Documentación pública de la API: especificación OpenAPI y visor. */
class DocsController extends Controller
{
    public function spec(): JsonResponse
    {
        return response()->json(OpenApi::spec(url('/api/v1')), 200, [
            'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function page(): Response
    {
        return response()->view('api.docs', ['spec' => OpenApi::spec(url('/api/v1')), 'home' => url('/'), 'specUrl' => url('/api/v1/openapi.json')]);
    }
}
