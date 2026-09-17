<?php

use Danmahara\LaravelOpenApi\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;

Route::get(config('openapi.ui_route', 'api/documentation'), [OpenApiController::class, 'ui'])
    ->name('openapi.ui');

Route::get(config('openapi.spec_route', 'api/documentation.json'), [OpenApiController::class, 'spec'])
    ->name('openapi.spec');
