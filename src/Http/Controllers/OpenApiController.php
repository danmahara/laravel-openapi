<?php

namespace Danmahara\LaravelOpenApi\Http\Controllers;

use Danmahara\LaravelOpenApi\Generator\OpenApiGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;

class OpenApiController
{
    public function spec(Router $router): JsonResponse
    {
        $generator = new OpenApiGenerator($router, config('openapi', []));

        $spec = app()->environment('local', 'testing')
            ? $generator->generate()
            : Cache::remember('openapi.spec', now()->addHours(6), fn() => $generator->generate());

        return response()->json($spec);
    }

    public function ui(): View
    {
        return view('laravel-openapi::ui', [
            'specUrl' => route('openapi.spec'),
        ]);
    }
}
