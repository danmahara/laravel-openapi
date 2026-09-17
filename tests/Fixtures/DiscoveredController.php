<?php

namespace Danmahara\LaravelOpenApi\Tests\Fixtures;

use Danmahara\LaravelOpenApi\Attributes\{ApiExclude, ApiOperation, ApiParameter, ApiRequestBody, ApiResponse, ApiSecurity};
use Illuminate\Routing\Controller;

class DiscoveredController extends Controller
{
    public function __construct()
    {
        $this->middleware('controller-middleware');
    }

    public function __invoke(SampleFormRequest $request): SampleResource
    {
        throw new \LogicException('Discovery must not execute actions.');
    }

    #[ApiOperation(summary: 'Manual summary', operationId: 'manual')]
    #[ApiParameter(name: 'id', in: 'path', type: 'integer', description: 'User ID')]
    #[ApiRequestBody(schema: ['type' => 'string'])]
    #[ApiResponse(status: 201, schema: ['type' => 'boolean'])]
    #[ApiSecurity(name: 'bearerAuth')]
    public function manual(SampleFormRequest $request, string $id): SampleResource
    {
        throw new \LogicException('Discovery must not execute actions.');
    }

    #[ApiExclude]
    public function excluded(): void {}
}
