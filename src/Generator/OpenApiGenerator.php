<?php

namespace Danmahara\LaravelOpenApi\Generator;

use Danmahara\LaravelOpenApi\Attributes\ApiExclude;
use Danmahara\LaravelOpenApi\Attributes\ApiOperation;
use Danmahara\LaravelOpenApi\Attributes\ApiParameter;
use Danmahara\LaravelOpenApi\Attributes\ApiRequestBody;
use Danmahara\LaravelOpenApi\Attributes\ApiResponse;
use Danmahara\LaravelOpenApi\Attributes\ApiSecurity;
use Danmahara\LaravelOpenApi\Attributes\ApiTag;
use Danmahara\LaravelOpenApi\Discovery\RouteDiscovery;
use Danmahara\LaravelOpenApi\Discovery\MiddlewareSecurityResolver;
use Danmahara\LaravelOpenApi\SchemaInference\FormRequestSchemaBuilder;
use Danmahara\LaravelOpenApi\SchemaInference\ResourceSchemaBuilder;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionMethod;

class OpenApiGenerator
{
    public function __construct(
        private readonly Router $router,
        private readonly array $config = [],
        private readonly FormRequestSchemaBuilder $formRequestBuilder = new FormRequestSchemaBuilder(),
        private readonly ResourceSchemaBuilder $resourceBuilder = new ResourceSchemaBuilder(),
    ) {
    }

    public function generate(): array
    {
        $paths = [];
        $tags = [];
        $securitySchemes = $this->config['security_schemes'] ?? [];
        $securityResolver = new MiddlewareSecurityResolver($this->config['middleware_security'] ?? []);

        foreach ($this->router->getRoutes() as $route) {
            if (!$this->shouldInclude($route)) {
                continue;
            }

            $reflectionMethod = (new RouteDiscovery())->action($route);

            if ($reflectionMethod === null) {
                continue;
            }

            if ($reflectionMethod->getAttributes(ApiExclude::class) !== []) {
                continue;
            }

            $reflectionClass = $reflectionMethod instanceof ReflectionMethod ? $reflectionMethod->getDeclaringClass() : null;
            $operation = $this->buildOperation($reflectionClass, $reflectionMethod, $route);
            if (!isset($operation['security'])) {
                $detectedSchemes = $securityResolver->resolve($operation['x-laravel-middleware'] ?? []);
                if ($detectedSchemes !== []) {
                    // Multiple middleware run together, so these requirements are ANDed.
                    $operation['security'] = [array_fill_keys(array_keys($detectedSchemes), [])];
                }
                // Configured definitions always win over inferred defaults.
                $securitySchemes += $detectedSchemes;
            }
            $path = $this->normalizePath($route->uri());

            foreach ($route->methods() as $httpMethod) {
                $httpMethod = strtolower($httpMethod);

                if (!in_array($httpMethod, ['get', 'post', 'put', 'patch', 'delete', 'options'], true)) {
                    continue;
                }

                $paths[$path][$httpMethod] = $operation;
            }

            foreach ($operation['tags'] ?? [] as $tagName) {
                $tags[$tagName] = $tagName;
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => array_filter([
                'title' => $this->config['title'] ?? 'API',
                'version' => $this->config['version'] ?? '1.0.0',
                'description' => $this->config['description'] ?? '',
            ]),
            'servers' => $this->config['servers'] ?? [],
            'tags' => array_values(array_map(fn($name) => ['name' => $name], $tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $securitySchemes,
            ],
        ];
    }

    private function shouldInclude(Route $route): bool
    {
        if (in_array($route->getName(), ['openapi.ui', 'openapi.spec'], true)) {
            return false;
        }

        $includePrefixes = $this->config['include'] ?? ['api/*'];
        $excludePrefixes = $this->config['exclude'] ?? [];
        $uri = $route->uri();

        foreach ($excludePrefixes as $pattern) {
            if (Str::is($pattern, $uri)) {
                return false;
            }
        }

        foreach ($includePrefixes as $pattern) {
            if (Str::is($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function buildOperation(?ReflectionClass $class, ReflectionFunctionAbstract $method, Route $route): array
    {
        $operationAttr = $method->getAttributes(ApiOperation::class)[0] ?? null;
        /** @var ApiOperation $operation */
        $operation = $operationAttr ? $operationAttr->newInstance() : new ApiOperation();

        $classTagAttr = $class?->getAttributes(ApiTag::class)[0] ?? null;
        $tags = $operation->tags;

        if (empty($tags) && $classTagAttr) {
            $tags = [$classTagAttr->newInstance()->name];
        }

        if (empty($tags)) {
            $tags = [$class?->getShortName() ?? 'Closure'];
        }

        $result = array_filter([
            // Reflected closure names can contain source locations.
            'summary' => $operation->summary ?: ($class ? $method->getName() : $this->normalizePath($route->uri())),
            'description' => $operation->description,
            'operationId' => $operation->operationId ?? ($class ? $class->getShortName() . '::' . $method->getName() : ($route->getName() ?? 'Closure::' . $route->uri())),
            'tags' => $tags,
            'deprecated' => $operation->deprecated ?: null,
            'x-laravel-middleware' => (new RouteDiscovery())->middleware($route, $this->router),
            'parameters' => $this->buildParameters($method, $route),
            'requestBody' => $this->buildRequestBody($method),
            'responses' => $this->buildResponses($method) ?: ['200' => ['description' => 'Successful response']],
            'security' => $this->buildSecurity($class, $method) ?: null,
        ], fn($v) => $v !== null && $v !== []);

        return $result;
    }

    private function buildParameters(ReflectionFunctionAbstract $method, Route $route): array
    {
        $parameters = [];

        preg_match_all('/\{([^}?:]+)(?:\?)?\}/', $route->uri(), $matches);

        foreach ($matches[1] as $name) {
            $parameters['path:' . $name] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string'],
            ];
        }

        foreach ($method->getAttributes(ApiParameter::class) as $attr) {
            /** @var ApiParameter $param */
            $param = $attr->newInstance();

            $schema = ['type' => $param->type];

            if ($param->enum) {
                $schema['enum'] = $param->enum;
            }

            $parameters[$param->in . ':' . $param->name] = array_filter([
                'name' => $param->name,
                'in' => $param->in,
                'required' => $param->in === 'path' ? true : $param->required,
                'description' => $param->description ?: null,
                'schema' => $schema,
                'example' => $param->example,
            ], fn($v) => $v !== null && $v !== '');
        }

        return array_values($parameters);
    }

    private function buildRequestBody(ReflectionFunctionAbstract $method): ?array
    {
        $attr = $method->getAttributes(ApiRequestBody::class)[0] ?? null;

        $requests = (new RouteDiscovery())->formRequests($method);
        if (!$attr && count($requests) !== 1) {
            return null;
        }

        /** @var ApiRequestBody $body */
        $body = $attr ? $attr->newInstance() : new ApiRequestBody(formRequest: $requests[0]);

        $schema = $body->schema
            ?? ($body->formRequest ? $this->formRequestBuilder->build($body->formRequest) : ['type' => 'object']);

        return [
            'description' => $body->description,
            'required' => $body->required,
            'content' => [
                $body->contentType => ['schema' => $schema],
            ],
        ];
    }

    private function buildResponses(ReflectionFunctionAbstract $method): array
    {
        $responses = [];

        foreach ($method->getAttributes(ApiResponse::class) as $attr) {
            /** @var ApiResponse $response */
            $response = $attr->newInstance();

            $schema = $response->schema
                ?? ($response->resource ? $this->resourceBuilder->build($response->resource) : ['type' => 'object']);

            if ($response->isCollection) {
                $schema = ['type' => 'array', 'items' => $schema];
            }

            $responses[(string) $response->status] = [
                'description' => $response->description ?: 'Response',
                'content' => [
                    'application/json' => ['schema' => $schema],
                ],
            ];
        }

        if ($responses === [] && ($resource = (new RouteDiscovery())->resource($method))) {
            $responses['200'] = [
                'description' => 'Successful response',
                'content' => ['application/json' => ['schema' => $this->resourceBuilder->build($resource)]],
            ];
        }

        return $responses;
    }

    private function buildSecurity(?ReflectionClass $class, ReflectionFunctionAbstract $method): array
    {
        $attrs = array_merge(
            $method->getAttributes(ApiSecurity::class),
            $class?->getAttributes(ApiSecurity::class) ?? []
        );

        $security = [];

        foreach ($attrs as $attr) {
            /** @var ApiSecurity $sec */
            $sec = $attr->newInstance();
            $requirement = [$sec->name => $sec->scopes];
            if (!in_array($requirement, $security, true)) {
                $security[] = $requirement;
            }
        }

        return $security;
    }

    private function normalizePath(string $uri): string
    {
        $path = '/' . ltrim($uri, '/');

        return preg_replace('/\{([a-zA-Z0-9_]+)\??\}/', '{$1}', $path);
    }
}
