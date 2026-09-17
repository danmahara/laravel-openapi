<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Closure;
use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use LogicException;

class MiddlewareDiscoveryRegressionTest extends TestCase
{
    public function test_failed_controller_resolution_preserves_security_across_generations(): void
    {
        $route = null;
        Route::middleware('auth:api')->group(function () use (&$route) {
            $route = Route::get('api/unresolvable', FailingDiscoveryController::class)->middleware('auth:sanctum');
        });
        $generator = new OpenApiGenerator($this->app['router']);
        $first = $generator->generate();
        $this->assertSame([['api' => [], 'sanctum' => []]], $first['paths']['/api/unresolvable']['get']['security']);
        $this->assertSame($first, $generator->generate());
        $this->assertSame($first, $generator->generate());
        $this->assertNull($route->computedMiddleware);
    }

    public function test_resolved_class_exclusions_remove_route_and_controller_authentication(): void
    {
        $this->app['router']->aliasMiddleware('auth', Authenticate::class);
        Route::get('api/route', fn () => [])->middleware('auth')->withoutMiddleware(Authenticate::class);
        Route::get('api/controller', ExcludedDiscoveryController::class)->withoutMiddleware(Authenticate::class);
        Route::middleware('auth')->group(function () {
            Route::get('api/group', fn () => [])->withoutMiddleware(Authenticate::class);
        });
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        foreach (['route', 'controller', 'group'] as $name) {
            $this->assertArrayNotHasKey('security', $document['paths']['/api/'.$name]['get']);
        }
        $this->assertSame([], $document['components']['securitySchemes']);
    }

    public function test_parameterized_alias_and_class_exclusions_follow_laravel(): void
    {
        $this->app['router']->aliasMiddleware('auth', Authenticate::class);
        $this->app['router']->aliasMiddleware('same-auth', Authenticate::class);
        Route::get('api/alias', fn () => [])->middleware('auth:sanctum')->withoutMiddleware('same-auth:sanctum');
        Route::get('api/class', fn () => [])->middleware('auth:api')->withoutMiddleware(Authenticate::class.':api');
        // Laravel preserves parameterized middleware when only the bare class is excluded.
        Route::get('api/retained', fn () => [])->middleware('auth:sanctum')->withoutMiddleware(Authenticate::class);
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $this->assertArrayNotHasKey('security', $document['paths']['/api/alias']['get']);
        $this->assertArrayNotHasKey('security', $document['paths']['/api/class']['get']);
        $this->assertSame([['sanctum' => []]], $document['paths']['/api/retained']['get']['security']);
    }

    public function test_inline_closure_with_exclusions_does_not_break_security_discovery(): void
    {
        Route::get('api/inline', InlineDiscoveryController::class)->withoutMiddleware('unused');
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $operation = $document['paths']['/api/inline']['get'];
        $this->assertSame([['sanctum' => []]], $operation['security']);
        $this->assertInstanceOf(Closure::class, $operation['x-laravel-middleware'][0]);
        $this->assertSame('auth:sanctum', $operation['x-laravel-middleware'][1]);
    }

    public function test_nested_controller_metadata_is_flattened_without_expanding_named_groups(): void
    {
        $this->app['router']->middlewareGroup('private-group', ['auth:api']);
        Route::get('api/nested', NestedDiscoveryController::class)->withoutMiddleware(['unused', Authenticate::class]);
        Route::get('api/opaque', fn () => [])->middleware('private-group');
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $this->assertSame([['sanctum' => []]], $document['paths']['/api/nested']['get']['security']);
        $this->assertCount(2, $document['paths']['/api/nested']['get']['x-laravel-middleware']);
        $this->assertSame(['auth:api'], $this->app['router']->getMiddlewareGroups()['private-group']);
        $this->assertArrayNotHasKey('security', $document['paths']['/api/opaque']['get']);
        $this->assertSame(['private-group'], $document['paths']['/api/opaque']['get']['x-laravel-middleware']);
    }
}

class FailingDiscoveryController extends Controller
{
    public function __construct(string $missing) {}

    public function __invoke(): void
    {
        throw new LogicException('Discovery must not execute actions.');
    }
}

class ExcludedDiscoveryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function __invoke(): void
    {
        throw new LogicException('Discovery must not execute actions.');
    }
}

class InlineDiscoveryController extends Controller
{
    public function __construct()
    {
        $this->middleware(fn ($request, $next) => $next($request));
        $this->middleware('auth:sanctum');
    }

    public function __invoke(): void
    {
        throw new LogicException('Discovery must not execute actions.');
    }
}

class NestedDiscoveryController extends InlineDiscoveryController
{
    public function getMiddleware()
    {
        return [['middleware' => [[fn ($request, $next) => $next($request), 'auth:sanctum', Authenticate::class]], 'options' => []]];
    }
}
