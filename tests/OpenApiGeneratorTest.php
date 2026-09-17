<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Danmahara\LaravelOpenApi\Tests\Fixtures\SampleController;
use Illuminate\Support\Facades\Route;

class OpenApiGeneratorTest extends TestCase
{
    public function test_it_generates_paths_for_registered_routes(): void
    {
        Route::get('api/users', [SampleController::class, 'index']);
        Route::post('api/users', [SampleController::class, 'store']);

        $generator = new OpenApiGenerator($this->app['router'], [
            'include' => ['api/*'],
            'title' => 'Test API',
            'version' => '1.0.0',
        ]);

        $spec = $generator->generate();

        $this->assertArrayHasKey('/api/users', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/api/users']);
        $this->assertArrayHasKey('post', $spec['paths']['/api/users']);

        $this->assertSame(
            'array',
            $spec['paths']['/api/users']['get']['responses']['200']['content']['application/json']['schema']['type']
        );

        $this->assertSame(
            ['name', 'role'],
            $spec['paths']['/api/users']['post']['requestBody']['content']['application/json']['schema']['required']
        );
    }

    public function test_routes_outside_include_pattern_are_skipped(): void
    {
        Route::get('web/dashboard', [SampleController::class, 'index']);

        $generator = new OpenApiGenerator($this->app['router'], ['include' => ['api/*']]);
        $spec = $generator->generate();

        $this->assertArrayNotHasKey('/web/dashboard', $spec['paths']);
    }
}
