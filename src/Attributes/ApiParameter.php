<?php

namespace Danmahara\LaravelOpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiParameter
{
    public function __construct(
        public string $name,
        public string $in = 'query',
        public string $type = 'string',
        public bool $required = false,
        public string $description = '',
        public mixed $example = null,
        public ?array $enum = null,
    ) {
    }
}
