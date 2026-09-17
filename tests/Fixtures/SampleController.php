<?php

namespace Danmahara\LaravelOpenApi\Tests\Fixtures;

use Danmahara\LaravelOpenApi\Attributes\ApiOperation;
use Danmahara\LaravelOpenApi\Attributes\ApiParameter;
use Danmahara\LaravelOpenApi\Attributes\ApiRequestBody;
use Danmahara\LaravelOpenApi\Attributes\ApiResponse;
use Danmahara\LaravelOpenApi\Attributes\ApiTag;

#[ApiTag(name: 'Users')]
class SampleController
{
    #[ApiOperation(summary: 'List users')]
    #[ApiParameter(name: 'page', in: 'query', type: 'integer')]
    #[ApiResponse(status: 200, description: 'A list of users', isCollection: true)]
    public function index()
    {
    }

    #[ApiOperation(summary: 'Create a user')]
    #[ApiRequestBody(formRequest: SampleFormRequest::class)]
    #[ApiResponse(status: 201, description: 'Created user')]
    public function store()
    {
    }
}
