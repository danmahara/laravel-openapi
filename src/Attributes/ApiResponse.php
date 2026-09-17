<?php

namespace Danmahara\LaravelOpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiResponse
{
    /**
     * @param  class-string|null  $resource  A JsonResource class to infer the schema from its toArray().
     * @param  array|null  $schema  An explicit OpenAPI schema, used instead of/over resource.
     */
    public function __construct(
        public int $status = 200,
        public string $description = '',
        public ?string $resource = null,
        public ?array $schema = null,
        public bool $isCollection = false,
    ) {
    }
}
