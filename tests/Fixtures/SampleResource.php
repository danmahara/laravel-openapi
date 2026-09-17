<?php

namespace Danmahara\LaravelOpenApi\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

class SampleResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
