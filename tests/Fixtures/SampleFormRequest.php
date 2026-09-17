<?php

namespace Danmahara\LaravelOpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class SampleFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'age' => 'nullable|integer|min:0',
            'role' => 'required|in:admin,user',
        ];
    }
}
