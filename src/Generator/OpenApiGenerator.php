<?php

namespace Danmahara\LaravelOpenApi\Generator;

use Danmahara\LaravelOpenApi\Attributes\ApiExclude;
use Danmahara\LaravelOpenApi\Attributes\ApiOperation;
use Danmahara\LaravelOpenApi\Attributes\ApiParameter;
use Danmahara\LaravelOpenApi\Attributes\ApiRequestBody;
use Danmahara\LaravelOpenApi\Attributes\ApiResponse;
use Danmahara\LaravelOpenApi\Attributes\ApiSecurity;
use Danmahara\LaravelOpenApi\Attributes\ApiTag;
use Danmahara\LaravelOpenApi\SchemaInference\FormRequestSchemaBuilder;
use Danmahara\LaravelOpenApi\SchemaInference\ResourceSchemaBuilder;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionClass;
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

        foreach ($this->router->getRoutes() as $route) {
            if (! $this->shouldInclude($route)) {
                continue;
            }

            $action = $this->resolveControllerAction($route);

            if ($action === null) {
                continue;
            }

            [$controllerClass, $methodName] = $action;

            if (! method_exists($controllerClass, $methodName)) {
                continue;
            }

            $reflectionMethod = new ReflectionMethod($controllerClass, $methodName);

            if ($reflectionMethod->getAttributes(ApiExclude::class) !== []) {
                continue;
            }

            $reflectionClass = $reflectionMethod->getDeclaringClass();
            $operation = $this->buildOperation($reflectionClass, $reflectionMethod, $route);
            $path = $this->normalizePath($route->uri());

            foreach ($route->methods() as $httpMethod) {
                $httpMethod = strtolower($httpMethod);

                if (! in_array($httpMethod, ['get', 'post', 'put', 'patch', 'delete', 'options'], true)) {
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
            'tags' => array_values(array_map(fn ($name) => ['name' => $name], $tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => $this->config['security_schemes'] ?? [],
            ],
        ];
    }

    private function shouldInclude(Route $route): bool
    {
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

    /**
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveControllerAction(Route $route): ?array
    {
        $action = $route->getAction('uses');

        if (is_string($action) && str_contains($action, '@')) {
            return explode('@', $action, 2);
        }

        $controller = $route->getController();

        if ($controller !== null) {
            $actionMethod = $route->getActionMethod();

            if ($actionMethod && $actionMethod !== 'Closure') {
                return [get_class($controller), $actionMethod];
            }
        }

        return null; // Closures carry no reflectable attributes and aren't documented.
    }

    private function buildOperation(ReflectionClass $class, ReflectionMethod $method, Route $route): array
    {
        $operationAttr = $method->getAttributes(ApiOperation::class)[0] ?? null;
        /** @var ApiOperation $operation */
        $operation = $operationAttr ? $operationAttr->newInstance() : new ApiOperation();

        $classTagAttr = $class->getAttributes(ApiTag::class)[0] ?? null;
        $tags = $operation->tags;

        if (empty($tags) && $classTagAttr) {
            $tags = [$classTagAttr->newInstance()->name];
        }

        if (empty($tags)) {
            $tags = [$class->getShortName()];
        }

        $result = array_filter([
            'summary' => $operation->summary ?: $method->getName(),
            'description' => $operation->description,
            'operationId' => $operation->operationId ?? ($class->getShortName().'::'.$method->getName()),
            'tags' => $tags,
            'deprecated' => $operation->deprecated ?: null,
            'parameters' => $this->buildParameters($method, $route),
            'requestBody' => $this->buildRequestBody($method),
            'responses' => $this->buildResponses($method) ?: ['200' => ['description' => 'Successful response']],
            'security' => $this->buildSecurity($class, $method) ?: null,
        ], fn ($v) => $v !== null && $v !== []);

        return $result;
    }

    private function buildParameters(ReflectionMethod $method, Route $route): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[] = [
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

            $parameters[] = array_filter([
                'name' => $param->name,
                'in' => $param->in,
                'required' => $param->in === 'path' ? true : $param->required,
                'description' => $param->description ?: null,
                'schema' => $schema,
                'example' => $param->example,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $parameters;
    }

    private function buildRequestBody(ReflectionMethod $method): ?array
    {
        $attr = $method->getAttributes(ApiRequestBody::class)[0] ?? null;

        if (! $attr) {
            return null;
        }

        /** @var ApiRequestBody $body */
        $body = $attr->newInstance();

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

    private function buildResponses(ReflectionMethod $method): array
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

        return $responses;
    }

    private function buildSecurity(ReflectionClass $class, ReflectionMethod $method): array
    {
        $attrs = array_merge(
            $method->getAttributes(ApiSecurity::class),
            $class->getAttributes(ApiSecurity::class)
        );

        $security = [];

        foreach ($attrs as $attr) {
            /** @var ApiSecurity $sec */
            $sec = $attr->newInstance();
            $security[] = [$sec->name => $sec->scopes];
        }

        return $security;
    }

    private function normalizePath(string $uri): string
    {
        $path = '/'.ltrim($uri, '/');

        return preg_replace('/\{([a-zA-Z0-9_]+)\??\}/', '{$1}', $path);
    }
}
