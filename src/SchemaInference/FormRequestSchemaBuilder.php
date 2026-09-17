<?php

namespace Danmahara\LaravelOpenApi\SchemaInference;

use ReflectionClass;
use Throwable;

/**
 * Builds a request-body schema from a Laravel FormRequest by reading its
 * rules() method. Nested/wildcard rules (e.g. "items.*.name") are skipped
 * at depth rather than guessed, since flattening them correctly needs more
 * context than a rule string gives.
 */
class FormRequestSchemaBuilder
{
    public function build(string $formRequestClass): array
    {
        if (! class_exists($formRequestClass)) {
            return ['type' => 'object'];
        }

        $rules = $this->extractRules($formRequestClass);

        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.')) {
                continue;
            }

            $ruleList = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            $ruleNames = array_map(
                fn ($r) => is_string($r) ? explode(':', $r)[0] : '',
                $ruleList
            );

            if (in_array('required', $ruleNames, true)) {
                $required[] = $field;
            }

            $properties[$field] = $this->ruleListToSchema($ruleList);
        }

        $schema = ['type' => 'object', 'properties' => $properties];

        if (! empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function extractRules(string $formRequestClass): array
    {
        try {
            $reflection = new ReflectionClass($formRequestClass);
            $instance = $reflection->newInstanceWithoutConstructor();

            if (! method_exists($instance, 'rules')) {
                return [];
            }

            $method = $reflection->getMethod('rules');
            $method->setAccessible(true);
            $rules = $method->invoke($instance);

            return is_array($rules) ? $rules : [];
        } catch (Throwable) {
            // rules() that depend on container bindings, the current route,
            // or auth state can't be resolved statically outside an HTTP
            // request; such fields are simply omitted from the schema.
            return [];
        }
    }

    private function ruleListToSchema(array $ruleList): array
    {
        $schema = ['type' => 'string'];

        foreach ($ruleList as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            switch ($name) {
                case 'integer':
                case 'int':
                    $schema['type'] = 'integer';
                    break;
                case 'numeric':
                    $schema['type'] = 'number';
                    break;
                case 'boolean':
                case 'bool':
                    $schema['type'] = 'boolean';
                    break;
                case 'array':
                    $schema['type'] = 'array';
                    $schema['items'] = ['type' => 'string'];
                    break;
                case 'date':
                case 'date_format':
                    $schema['type'] = 'string';
                    $schema['format'] = 'date-time';
                    break;
                case 'email':
                    $schema['format'] = 'email';
                    break;
                case 'uuid':
                    $schema['format'] = 'uuid';
                    break;
                case 'in':
                    if ($param !== null) {
                        $schema['enum'] = explode(',', $param);
                    }
                    break;
                case 'min':
                    if ($param !== null) {
                        $key = $schema['type'] === 'string' ? 'minLength' : 'minimum';
                        $schema[$key] = is_numeric($param) ? $param + 0 : $param;
                    }
                    break;
                case 'max':
                    if ($param !== null) {
                        $key = $schema['type'] === 'string' ? 'maxLength' : 'maximum';
                        $schema[$key] = is_numeric($param) ? $param + 0 : $param;
                    }
                    break;
                case 'nullable':
                    $schema['nullable'] = true;
                    break;
            }
        }

        return $schema;
    }
}
