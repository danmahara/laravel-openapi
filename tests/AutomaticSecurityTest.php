<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Danmahara\LaravelOpenApi\Attributes\ApiSecurity;
use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Danmahara\LaravelOpenApi\Tests\Fixtures\DiscoveredController;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

class AutomaticSecurityTest extends TestCase
{
    public function test_public_and_supported_authentication_middleware(): void
    {
        Route::get('api/public', fn () => []);
        foreach (['auth:sanctum' => 'sanctum', 'auth:api' => 'api', 'auth' => 'auth'] as $middleware => $scheme) {
            Route::get('api/'.$scheme, fn () => [])->middleware($middleware);
        }

        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $this->assertArrayNotHasKey('security', $document['paths']['/api/public']['get']);
        $this->assertCount(3, $document['components']['securitySchemes']);
        foreach (['sanctum', 'api', 'auth'] as $scheme) {
            $this->assertSame([[$scheme => []]], $document['paths']['/api/'.$scheme]['get']['security']);
            $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], $document['components']['securitySchemes'][$scheme]);
        }
    }

    public function test_group_controller_and_repeated_middleware_reuse_schemes(): void
    {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('api/one', fn () => [])->middleware(['auth:sanctum', 'auth:sanctum']);
            Route::get('api/two', AuthenticatedController::class);
        });
        Route::get('api/three', [AuthenticatedController::class, '__invoke']);

        $generator = new OpenApiGenerator($this->app['router']);
        $document = $generator->generate();
        $this->assertSame($document, $generator->generate());
        $this->assertSame(['sanctum'], array_keys($document['components']['securitySchemes']));
        foreach (['one', 'two', 'three'] as $name) {
            $this->assertSame([['sanctum' => []]], $document['paths']['/api/'.$name]['get']['security']);
        }
    }

    public function test_explicit_method_and_class_security_override_detection_and_deduplicate(): void
    {
        Route::post('api/manual', [DiscoveredController::class, 'manual'])->middleware('auth:sanctum');
        Route::get('api/class', ExplicitSecurityController::class)->middleware('auth:api');
        $custom = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'];
        $document = (new OpenApiGenerator($this->app['router'], ['security_schemes' => ['bearerAuth' => $custom]]))->generate();

        $this->assertSame([['bearerAuth' => []]], $document['paths']['/api/manual']['post']['security']);
        $this->assertSame([['bearerAuth' => []]], $document['paths']['/api/class']['get']['security']);
        $this->assertSame(['bearerAuth' => $custom], $document['components']['securitySchemes']);
    }

    public function test_configured_definitions_win_and_custom_mappings_can_extend_or_disable_detection(): void
    {
        Route::get('api/token', fn () => [])->middleware('auth:sanctum');
        Route::get('api/custom', fn () => [])->middleware(['custom-auth', 'auth:sanctum']);
        Route::get('api/disabled', fn () => [])->middleware('auth');
        $custom = ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Token'];
        $document = (new OpenApiGenerator($this->app['router'], [
            'security_schemes' => ['sanctum' => $custom],
            'middleware_security' => ['custom-auth' => ['sanctum' => $custom], 'auth' => []],
        ]))->generate();

        $this->assertSame(['sanctum' => $custom], $document['components']['securitySchemes']);
        $this->assertSame([['sanctum' => []]], $document['paths']['/api/custom']['get']['security']);
        $this->assertArrayNotHasKey('security', $document['paths']['/api/disabled']['get']);
    }

    public function test_excluded_unknown_and_filtered_middleware_do_not_add_security(): void
    {
        Route::get('api/excluded', fn () => [])->middleware('auth:sanctum')->withoutMiddleware('auth:sanctum');
        Route::get('api/unknown', fn () => [])->middleware(['auth:custom', 'auth.basic']);
        Route::get('web/filtered', fn () => [])->middleware('auth:api');
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $this->assertSame([], $document['components']['securitySchemes']);
        foreach ($document['paths'] as $path) {
            $this->assertArrayNotHasKey('security', $path['get']);
        }
    }

    public function test_distinct_authentication_middleware_are_required_together(): void
    {
        Route::get('api/both', fn () => [])->middleware(['auth:sanctum', 'auth:api']);
        $document = (new OpenApiGenerator($this->app['router']))->generate();
        $this->assertSame([['sanctum' => [], 'api' => []]], $document['paths']['/api/both']['get']['security']);
    }
}

class AuthenticatedController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function __invoke(): array
    {
        return [];
    }
}

#[ApiSecurity(name: 'bearerAuth')]
class ExplicitSecurityController
{
    #[ApiSecurity(name: 'bearerAuth')]
    #[ApiSecurity(name: 'bearerAuth')]
    public function __invoke(): array
    {
        return [];
    }
}
