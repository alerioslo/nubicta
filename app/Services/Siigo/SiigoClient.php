<?php

namespace App\Services\Siigo;

use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de solo lectura para la API de Siigo. Solo expone GET: el ERP nunca escribe en Siigo.
 */
class SiigoClient
{
    private const BASE = 'https://api.siigo.com';

    public function __construct(
        private readonly string $username,
        private readonly string $accessKey,
        private readonly string $partnerId,
        private readonly int $pauseMs = 1000,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('services.siigo');
        if (empty($c['username']) || empty($c['access_key'])) {
            throw new RuntimeException('Faltan SIIGO_USERNAME / SIIGO_ACCESS_KEY en el .env');
        }

        return new self($c['username'], $c['access_key'], $c['partner_id'] ?? 'ERP', (int) ($c['pause_ms'] ?? 1000));
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request($path, $query)->json() ?? [];
    }

    /** Recorre todas las paginas de un endpoint paginado y entrega cada registro. */
    public function paginate(string $path, array $query = [], int $pageSize = 100): Generator
    {
        $page = 1;
        do {
            $body = $this->get($path, [...$query, 'page' => $page, 'page_size' => $pageSize]);
            $results = $body['results'] ?? (array_is_list($body) ? $body : []);
            foreach ($results as $row) {
                yield $row;
            }
            $hasNext = isset($body['_links']['next']) && count($results) > 0;
            $page++;
            if ($hasNext) {
                usleep($this->pauseMs * 1000);
            }
        } while ($hasNext);
    }

    public function total(string $path, array $query = []): int
    {
        $body = $this->get($path, [...$query, 'page' => 1, 'page_size' => 1]);

        return (int) ($body['pagination']['total_results'] ?? count($body['results'] ?? $body));
    }

    private function request(string $path, array $query, int $attempt = 1): Response
    {
        $response = Http::withHeaders([
            'Authorization' => $this->token(),
            'Partner-Id' => $this->partnerId,
        ])->acceptJson()->timeout(120)->get(self::BASE.'/'.ltrim($path, '/'), $query);

        if ($response->status() === 401 && $attempt === 1) {
            Cache::forget($this->cacheKey());

            return $this->request($path, $query, 2);
        }

        if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt <= 5) {
            sleep(min(60, 5 * $attempt * $attempt));

            return $this->request($path, $query, $attempt + 1);
        }

        if ($response->failed()) {
            $msg = $response->json('Errors.0.Message') ?? $response->body();
            throw new RuntimeException("Siigo GET $path respondió {$response->status()}: ".mb_substr((string) $msg, 0, 500));
        }

        return $response;
    }

    private function token(): string
    {
        return Cache::remember($this->cacheKey(), now()->addHours(23), function () {
            $response = Http::withHeaders(['Partner-Id' => $this->partnerId])
                ->acceptJson()
                ->post(self::BASE.'/auth', ['username' => $this->username, 'access_key' => $this->accessKey]);

            if ($response->failed() || ! $response->json('access_token')) {
                throw new RuntimeException('No se pudo autenticar con Siigo: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    private function cacheKey(): string
    {
        return 'siigo_token_'.md5($this->username);
    }
}
