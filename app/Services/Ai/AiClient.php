<?php

namespace App\Services\Ai;

use App\Services\Mail\CompanyMailer;
use App\Services\Sales\CollectionAutomation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente de chat compatible con la API de OpenAI (/chat/completions), configurado en
 * Configuracion > General > Inteligencia artificial (app_settings, clave "ia").
 */
class AiClient
{
    public function __construct(private readonly array $settings) {}

    public static function forCurrentCompany(): self
    {
        return new self(CollectionAutomation::settings('ia'));
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? false) && $this->configured();
    }

    public function configured(): bool
    {
        return filled($this->settings['url'] ?? null) && filled($this->settings['token'] ?? null) && filled($this->settings['model'] ?? null);
    }

    public function model(): string
    {
        return trim((string) ($this->settings['model'] ?? ''));
    }

    public function minConfidence(): int
    {
        return max(0, min(100, (int) ($this->settings['min_confidence'] ?? 70)));
    }

    /** @param list<array{role:string, content:string}> $messages */
    public function chat(array $messages, ?int $timeout = null): string
    {
        $timeout ??= max(10, (int) ($this->settings['timeout'] ?? 90));
        @set_time_limit($timeout + 30);

        $response = Http::withToken(CompanyMailer::reveal((string) $this->settings['token']))
            ->acceptJson()->timeout($timeout)->connectTimeout(10)
            ->retry(3, fn (int $attempt, Throwable $e) => $e instanceof RequestException
                ? 1000 * min(30, max((int) $e->response->header('Retry-After'), 5 * $attempt)) : 0,
                fn (Throwable $e) => $e instanceof RequestException && $e->response->status() === 429, throw: false)
            ->post($this->endpoint(), ['model' => $this->model(), 'messages' => $messages, 'temperature' => 0]);

        if ($response->status() === 429) {
            throw new RuntimeException('El proveedor de IA rechazó la solicitud por límite de uso (HTTP 429). Espera un momento e intenta de nuevo; los modelos gratuitos permiten pocas solicitudes por minuto.');
        }
        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->json('message') ?? mb_substr($response->body(), 0, 300);
            throw new RuntimeException("HTTP {$response->status()}: ".(is_string($error) ? $error : json_encode($error)));
        }
        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('La IA respondió sin contenido');
        }

        return $content;
    }

    /** Pide una respuesta JSON y la decodifica (tolera bloques ```json y razonamientos <think>). */
    public function json(array $messages): array
    {
        $text = preg_replace('/<think>.*?<\/think>/s', '', $this->chat($messages));
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
            $text = $m[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('La IA no devolvió un JSON válido');
        }

        return $data;
    }

    private function endpoint(): string
    {
        $url = rtrim(trim((string) $this->settings['url']), '/');

        return str_ends_with($url, '/chat/completions') ? $url : "$url/chat/completions";
    }
}
