<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Danmahara\LaravelOpenApi\LaravelOpenApiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelOpenApiServiceProvider::class];
    }
}
