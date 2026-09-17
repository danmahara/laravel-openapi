<?php

namespace Danmahara\LaravelOpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiRequestBody
{
    /**
     * @param  class-string|null  $formRequest  A FormRequest class to infer the schema from its rules().
     * @param  array|null  $schema  An explicit OpenAPI schema, used instead of/over formRequest.
     */
    public function __construct(
        public ?string $formRequest = null,
        public ?array $schema = null,
        public string $description = '',
        public bool $required = true,
        public string $contentType = 'application/json',
    ) {
    }
}
