<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Danmahara\LaravelOpenApi\Tests\Fixtures\{DiscoveredController, SampleFormRequest, SampleResource};
use Illuminate\Support\Facades\Route;

class AutomaticDiscoveryTest extends TestCase
{
    public function test_all_methods_groups_parameters_and_middleware_are_discovered(): void
    {
        Route::prefix('api')->middleware('group-middleware')->group(function () {
            Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], 'users/{id?}', DiscoveredController::class);
        });
        $paths = (new OpenApiGenerator($this->app['router']))->generate()['paths'];
        $this->assertSame(['get', 'post', 'put', 'patch', 'delete', 'options'], array_keys($paths['/api/users/{id}']));
        foreach ($paths['/api/users/{id}'] as $operation) {
            $this->assertSame(['group-middleware', 'controller-middleware'], $operation['x-laravel-middleware']);
            $this->assertSame('id', $operation['parameters'][0]['name']);
            $this->assertTrue($operation['parameters'][0]['required']);
            $this->assertSame(['name', 'role'], $operation['requestBody']['content']['application/json']['schema']['required']);
            $this->assertSame('integer', $operation['responses'][200]['content']['application/json']['schema']['properties']['id']['type']);
        }
    }

    public function test_manual_metadata_overrides_inference_without_duplicate_parameters(): void
    {
        Route::post('api/users/{id}', [DiscoveredController::class, 'manual']);
        $operation = (new OpenApiGenerator($this->app['router']))->generate()['paths']['/api/users/{id}']['post'];
        $this->assertSame('Manual summary', $operation['summary']);
        $this->assertSame('manual', $operation['operationId']);
        $this->assertCount(1, $operation['parameters']);
        $this->assertSame(['type' => 'integer'], $operation['parameters'][0]['schema']);
        $this->assertSame('User ID', $operation['parameters'][0]['description']);
        $this->assertSame(['type' => 'string'], $operation['requestBody']['content']['application/json']['schema']);
        $this->assertSame([201], array_keys($operation['responses']));
        $this->assertSame(['type' => 'boolean'], $operation['responses'][201]['content']['application/json']['schema']);
        $this->assertSame([['bearerAuth' => []]], $operation['security']);
    }

    public function test_excluded_middleware_and_unresolvable_controller_dependencies(): void
    {
        Route::get('api/dependencies', UnresolvableController::class)
            ->middleware(['keep', 'remove'])->withoutMiddleware('remove');
        $operation = (new OpenApiGenerator($this->app['router']))->generate()['paths']['/api/dependencies']['get'];
        $this->assertSame(['keep'], $operation['x-laravel-middleware']);
    }

    public function test_closures_filters_exclusions_and_domain_parameters(): void
    {
        Route::domain('{tenant}.example.test')->post('api/closure/{id}', fn (SampleFormRequest $request): SampleResource => throw new \LogicException());
        Route::get('api/plain', fn () => []);
        Route::get('api/excluded', [DiscoveredController::class, 'excluded']);
        Route::get('api/hidden', DiscoveredController::class);
        Route::get('web/users', DiscoveredController::class);
        $paths = (new OpenApiGenerator($this->app['router'], ['exclude' => ['api/hidden']]))->generate()['paths'];
        $this->assertSame(['id'], array_column($paths['/api/closure/{id}']['post']['parameters'], 'name'));
        $this->assertArrayHasKey('requestBody', $paths['/api/closure/{id}']['post']);
        $this->assertSame([200 => ['description' => 'Successful response']], $paths['/api/plain']['get']['responses']);
        foreach (['/api/excluded', '/api/hidden', '/web/users'] as $path) {
            $this->assertArrayNotHasKey($path, $paths);
        }
    }
}

class UnresolvableController extends \Illuminate\Routing\Controller
{
    public function __construct(string $dependency) {}

    public function __invoke(): void {}
}
