<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Ai\AiClient;
use App\Services\Mail\CompanyMailer;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Services\Sales\CollectionAutomation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Paginas de configuracion declaradas en config/settings_pages.php. */
class SettingsPageController extends Controller
{
    public function show(string $page): Response
    {
        $def = config("settings_pages.$page") ?? abort(404);
        $values = $this->values($page, $def);
        $secrets = collect($this->fields($def))->where('type', 'password')->pluck('key');

        return Inertia::render('Settings/SettingsPage', [
            'page' => $page,
            'def' => $this->withOptions($def),
            'values' => [...$values, ...$secrets->mapWithKeys(fn ($k) => [$k => ''])->all()],
            'secretsSet' => $secrets->filter(fn ($k) => filled($values[$k]))->values(),
        ]);
    }

    public function update(Request $request, string $page): RedirectResponse
    {
        $def = config("settings_pages.$page") ?? abort(404);
        $current = $this->values($page, $def);
        $data = [];
        foreach ($this->fields($def) as $f) {
            $k = $f['key'];
            $data[$k] = match ($f['type']) {
                'readonly' => $current[$k],
                'toggle' => $request->boolean($k),
                'number' => is_numeric($request->input($k)) ? 0 + $request->input($k) : ($f['default'] ?? 0),
                'password' => filled($request->input($k)) ? (in_array($page, ['correos', 'ia'], true) ? CompanyMailer::protect($request->input($k)) : $request->input($k)) : $current[$k],
                default => mb_substr((string) $request->input($k, ''), 0, 5000),
            };
        }

        if ($request->input('action') === 'test') {
            $this->save($page, $data);

            return match ($page) {
                'correos' => $this->testMail($request),
                'api' => $this->newApiToken($request),
                'recordatorios' => $this->testReminder($request),
                'cobranza' => $this->runCollectionTasks(),
                'ia' => $this->testAi(),
                default => back()->with('success', 'Configuración guardada'),
            };
        }

        $this->save($page, $data);

        return back()->with('success', 'Configuración guardada');
    }

    /** Crea una llave de acceso para la API; solo se guarda su huella (SHA-256), así que se muestra una única vez. */
    private function newApiToken(Request $request): RedirectResponse
    {
        $token = 'erp_'.Str::random(48);
        DB::table('api_tokens')->whereNull('revoked_at')->where('company_id', CurrentCompany::id())->update(['revoked_at' => now()]);
        DB::table('api_tokens')->insert([
            'company_id' => CurrentCompany::id(), 'user_id' => $request->user()->id, 'name' => 'Llave '.now()->format('d/m/Y H:i'),
            'token' => hash('sha256', $token), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $data = $this->values('api', config('settings_pages.api'));
        $data['token_hint'] = substr($token, 0, 10).str_repeat('•', 12).' (generada el '.now()->format('d/m/Y H:i').')';
        $this->save('api', $data);

        return back()->with('success', "Llave generada: $token — cópiala ahora, no se volverá a mostrar. La llave anterior quedó revocada.");
    }

    private function testReminder(Request $request): RedirectResponse
    {
        if (! CompanyMailer::configured(CurrentCompany::id())) {
            return back()->with('error', 'Configura primero el servidor de correo (SMTP) en Desbloqueo de correos.');
        }
        $automation = app(CollectionAutomation::class);
        $doc = $automation->openInvoices()->sortBy('due_date')->first();
        if (! $doc) {
            return back()->with('error', 'No hay facturas con saldo pendiente para armar el recordatorio de prueba.');
        }
        try {
            $automation->sendReminder($doc, CollectionAutomation::settings('recordatorios'), $request->user()->email);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo enviar el recordatorio: '.$e->getMessage());
        }

        return back()->with('success', "Se envió a {$request->user()->email} el recordatorio de {$doc->name} tal como lo recibiría el cliente.");
    }

    private function runCollectionTasks(): RedirectResponse
    {
        if (! CollectionAutomation::settings('cobranza')['enabled']) {
            return back()->with('error', 'Activa "Generar tareas de cobranza automáticamente" y guarda antes de generar las tareas.');
        }
        $r = app(CollectionAutomation::class)->tasks();

        return back()->with('success', $r['created'] ? "Se generaron {$r['created']} tareas de cobranza. Las encuentras en Ventas > Seguimiento comercial." : 'Hoy no hay facturas que cumplan los días de gestión configurados.');
    }

    private function testAi(): RedirectResponse
    {
        $ai = AiClient::forCurrentCompany();
        if (! $ai->configured()) {
            return back()->with('error', 'Completa la URL de la API, el token y el modelo antes de probar la conexión.');
        }
        try {
            $answer = $ai->chat([['role' => 'user', 'content' => 'Responde únicamente con la palabra OK.']]);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo conectar con la IA: '.mb_substr($e->getMessage(), 0, 300));
        }

        return back()->with('success', 'Conexión exitosa con el modelo '.$ai->model().'. Respuesta: '.mb_substr(trim($answer), 0, 80));
    }

    /** Envia un correo real con el servidor guardado y muestra el error SMTP si falla. */
    private function testMail(Request $request): RedirectResponse
    {
        $company = CurrentCompany::id();
        if (! CompanyMailer::configured($company)) {
            return back()->with('error', 'Completa servidor, usuario y contraseña SMTP antes de enviar la prueba.');
        }
        [$fromEmail, $fromName] = CompanyMailer::from($company);
        $to = $request->user()->email ?: $fromEmail;
        try {
            CompanyMailer::mailer($company)->raw("Este es un correo de prueba enviado desde el ERP el ".now()->format('d/m/Y H:i').'. Si lo recibes, el servidor SMTP quedó bien configurado.',
                fn ($m) => $m->from($fromEmail, $fromName)->to($to)->subject('Correo de prueba del ERP'));
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo enviar el correo de prueba: '.mb_substr($e->getMessage(), 0, 300));
        }

        return back()->with('success', "Correo de prueba enviado a {$to}.");
    }

    private function fields(array $def): array
    {
        return collect($def['sections'])->flatMap(fn ($s) => $s['fields'])->all();
    }

    private function values(string $page, array $def): array
    {
        $saved = json_decode((string) DB::table('app_settings')->where('company_id', CurrentCompany::id())->where('key', $page)->value('value'), true) ?: [];
        $out = [];
        foreach ($this->fields($def) as $f) {
            $out[$f['key']] = $saved[$f['key']] ?? $f['default'] ?? ($f['type'] === 'toggle' ? false : '');
        }

        return $out;
    }

    private function save(string $page, array $data): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['company_id' => CurrentCompany::id(), 'key' => $page],
            ['value' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function withOptions(array $def): array
    {
        foreach ($def['sections'] as &$s) {
            foreach ($s['fields'] as &$f) {
                if (($f['options_from'] ?? null) === 'payment_methods') {
                    $f['options'] = PaymentMethod::where('active', true)->orderBy('name')->pluck('name')->all();
                }
                if ($f['type'] === 'password') {
                    $f['placeholder'] = 'Sin cambios';
                }
            }
        }

        return $def;
    }
}
