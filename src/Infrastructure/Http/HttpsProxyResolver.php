<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

final class HttpsProxyResolver
{
    public function resolve(): ?string
    {
        foreach (['FUTBIN_HTTPS_PROXY', 'EA_HTTPS_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
