<?php

namespace Danmahara\LaravelOpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class ApiOperation
{
    /**
     * @param  string[]  $tags
     */
    public function __construct(
        public string $summary = '',
        public string $description = '',
        public array $tags = [],
        public bool $deprecated = false,
        public ?string $operationId = null,
    ) {
    }
}
