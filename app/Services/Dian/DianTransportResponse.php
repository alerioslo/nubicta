<?php

namespace App\Services\Dian;

final readonly class DianTransportResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public int $durationMs = 0,
    ) {}
}
