<?php

namespace Danmahara\LaravelOpenApi\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiSecurity
{
    /**
     * @param  string[]  $scopes
     */
    public function __construct(
        public string $name,
        public array $scopes = [],
    ) {
    }
}
