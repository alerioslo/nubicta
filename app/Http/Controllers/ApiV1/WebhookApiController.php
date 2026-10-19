<?php

namespace App\Http\Controllers\ApiV1;

use App\Jobs\DeliverWebhook;
use App\Models\ApiWebhook;
use App\Services\Api\Webhooks;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Suscripciones a eventos: Nubicta hace POST firmado a la URL indicada cada vez que ocurre el evento. */
class WebhookApiController extends ApiBaseController
{
    public function events(): JsonResponse
    {
        return response()->json(['data' => collect(Webhooks::EVENTS)->map(fn ($d, $e) => ['evento' => $e, 'descripcion' => $d])->values()]);
    }

    public function index(): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');

        return response()->json(['data' => ApiWebhook::query()->orderBy('id')->get()->map(fn ($h) => $this->present($h))]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');

        return response()->json($this->present(ApiWebhook::query()->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');
        $data = $this->validated($request, true);
        if (ApiWebhook::query()->count() >= 20) {
            throw ValidationException::withMessages(['url' => 'Puedes tener hasta 20 webhooks.']);
        }
        $hook = ApiWebhook::create($data + ['secret' => Str::random(48), 'company_id' => CurrentCompany::id()]);

        return response()->json(['mensaje' => 'Se creó el webhook. Guarda el "secreto": se muestra solo esta vez y sirve para verificar la firma.',
            'data' => $this->present($hook) + ['secreto' => $hook->secret]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');
        $hook = ApiWebhook::query()->findOrFail($id);
        $hook->fill($this->validated($request, false) + ($request->has('activo') && $request->boolean('activo') ? ['failures' => 0] : []))->save();
        if ($request->boolean('regenerar_secreto')) {
            $hook->forceFill(['secret' => Str::random(48)])->save();

            return response()->json(['mensaje' => 'Se actualizó el webhook y se generó un secreto nuevo.', 'data' => $this->present($hook) + ['secreto' => $hook->secret]]);
        }

        return response()->json(['mensaje' => 'Se actualizó el webhook.', 'data' => $this->present($hook)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');
        ApiWebhook::query()->findOrFail($id)->delete();

        return response()->json(['mensaje' => 'Se eliminó el webhook.']);
    }

    public function test(int $id): JsonResponse
    {
        $this->authorizeApi('configuracion.parametros');
        $hook = ApiWebhook::query()->findOrFail($id);
        (new DeliverWebhook($hook->id, (int) $hook->company_id, 'prueba', 'prueba', 0))->handle();
        $hook->refresh();

        if ($hook->last_error) {
            return response()->json(['message' => 'La URL no respondió correctamente: '.$hook->last_error, 'data' => $this->present($hook)], 422);
        }

        return response()->json(['mensaje' => "La URL respondió HTTP {$hook->last_status}.", 'data' => $this->present($hook)]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'url', 'max:500'],
            'eventos' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'eventos.*' => [Rule::in([...array_keys(Webhooks::EVENTS), '*'])],
            'activo' => ['sometimes', 'boolean'],
        ], ['eventos.*.in' => 'Evento no válido. Consulta GET /webhooks/eventos.']);
        if ($request->filled('url') && ($error = Webhooks::safeUrl($request->input('url')))) {
            throw ValidationException::withMessages(['url' => $error]);
        }

        return array_filter([
            'url' => $request->input('url'), 'events' => $request->has('eventos') ? array_values(array_unique($request->input('eventos'))) : null,
            'active' => $request->has('activo') ? $request->boolean('activo') : null,
        ], fn ($v) => $v !== null);
    }

    private function present(ApiWebhook $h): array
    {
        return [
            'id' => $h->id, 'url' => $h->url, 'eventos' => $h->events, 'activo' => $h->active,
            'ultimo_envio' => $h->last_sent_at?->toIso8601String(), 'ultimo_estado_http' => $h->last_status, 'ultimo_error' => $h->last_error,
            'fallas_seguidas' => $h->failures, 'creado' => $h->created_at?->toIso8601String(),
        ];
    }
}
