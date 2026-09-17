<?php

namespace Danmahara\LaravelOpenApi\Discovery;

/** Maps discovered middleware to named OpenAPI schemes without executing authentication. */
class MiddlewareSecurityResolver
{
    public function __construct(private readonly array $mappings = [])
    {
    }

    /** @return array<string, array> Schemes keyed by their OpenAPI component name. */
    public function resolve(array $middleware): array
    {
        $bearer = ['type' => 'http', 'scheme' => 'bearer'];
        $mappings = array_replace([
            'auth:sanctum' => ['sanctum' => $bearer],
            'auth:api' => ['api' => $bearer],
            'auth' => ['auth' => $bearer],
        ], $this->mappings);

        $schemes = [];
        foreach ($middleware as $name) {
            if (is_string($name)) {
                $schemes += $mappings[$name] ?? [];
            }
        }

        return $schemes;
    }
}
