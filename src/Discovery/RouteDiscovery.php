<?php

namespace Danmahara\LaravelOpenApi\Discovery;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** Reflects actions without executing them; middleware inspection may construct controllers. */
class RouteDiscovery
{
    public function action(Route $route): ?ReflectionFunctionAbstract
    {
        $action = $route->getAction('uses');

        if ($action instanceof Closure) {
            return new ReflectionFunction($action);
        }

        if (is_string($action) && ! str_contains($action, '@') && method_exists($action, '__invoke')) {
            return new ReflectionMethod($action, '__invoke');
        }

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$controller, $method] = explode('@', $action, 2);

        return method_exists($controller, $method) ? new ReflectionMethod($controller, $method) : null;
    }

    /** Controller dependencies may be unavailable outside an HTTP request. */
    public function middleware(Route $route, ?Router $router = null): array
    {
        // Gather separately: gatherMiddleware() caches an empty array before resolving
        // the controller, leaving that cache empty if construction throws.
        $middleware = $route->middleware();
        try {
            $middleware = array_merge($middleware, $route->controllerMiddleware());
        } catch (BindingResolutionException) {
            // Keep route/group metadata and allow controller resolution to be retried.
        }

        $middleware = Router::uniqueMiddleware(Arr::flatten($middleware));
        $excluded = Arr::flatten($route->excludedMiddleware());
        if ($excluded === []) {
            return $middleware;
        }

        // The generator supplies its router; direct callers can use Laravel's binding.
        $container = Container::getInstance();
        $router ??= $container->bound('router') ? $container->make('router') : null;
        if ($router !== null) {
            // Use Laravel's alias, parameter and class-inheritance exclusion semantics,
            // but keep named groups opaque and do not mutate the application's router.
            $router = clone $router;
            $router->flushMiddlewareGroups();
        }

        return array_values(array_filter($middleware, function ($name) use ($router, $excluded) {
            if ($name instanceof Closure) {
                return true;
            }

            return $router !== null
                ? $router->resolveMiddleware([$name], $excluded) !== []
                : ! in_array($name, $excluded, true);
        }));
    }

    /** @return list<class-string<FormRequest>> */
    public function formRequests(ReflectionFunctionAbstract $action): array
    {
        $requests = [];
        foreach ($action->getParameters() as $parameter) {
            foreach ($this->classes($parameter->getType()) as $class) {
                if (is_a($class, FormRequest::class, true)) {
                    $requests[] = $class;
                }
            }
        }

        return array_values(array_unique($requests));
    }

    public function resource(ReflectionFunctionAbstract $action): ?string
    {
        $classes = $this->classes($action->getReturnType());
        // Ambiguous unions need explicit metadata, rather than guessing a schema.
        if (count($classes) !== 1) {
            return null;
        }

        return is_a($classes[0], JsonResource::class, true) ? $classes[0] : null;
    }

    private function classes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === 'null' ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_merge(...array_map(fn ($part) => $this->classes($part), $type->getTypes()));
        }

        return [];
    }
}
