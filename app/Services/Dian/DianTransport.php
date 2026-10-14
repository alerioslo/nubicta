<?php

namespace App\Services\Dian;

interface DianTransport
{
    /** @param array<string, string> $params */
    public function call(string $operation, array $params): DianTransportResponse;
}
