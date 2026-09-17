<?php

namespace Danmahara\LaravelOpenApi\Tests;

use Danmahara\LaravelOpenApi\Discovery\RouteDiscovery;
use Danmahara\LaravelOpenApi\Tests\Fixtures\{DiscoveredController, SampleFormRequest, SampleResource};
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

class RouteDiscoveryTest extends TestCase
{
    public function test_controller_and_invokable_actions_are_reflected(): void
    {
        $discovery = new RouteDiscovery();
        foreach ([DiscoveredController::class.'@manual', DiscoveredController::class] as $uses) {
            $action = $discovery->action(new Route('POST', 'api/users', $uses));
            $this->assertSame([SampleFormRequest::class], $discovery->formRequests($action));
            $this->assertSame(SampleResource::class, $discovery->resource($action));
        }
    }

    public function test_nullable_types_and_ambiguous_returns(): void
    {
        $discovery = new RouteDiscovery();
        $action = new ReflectionFunction(fn (?SampleFormRequest $request): ?SampleResource => null);
        $this->assertSame([SampleFormRequest::class], $discovery->formRequests($action));
        $this->assertSame(SampleResource::class, $discovery->resource($action));
        $this->assertNull($discovery->resource(new ReflectionFunction(fn (): SampleResource|string => '')));
        $this->assertNull($discovery->resource(new ReflectionFunction(fn () => null)));
        $this->assertNull($discovery->action(new Route('GET', 'api/missing', 'MissingController@show')));
    }
}
