<?php

namespace Danmahara\LaravelOpenApi\SchemaInference;

use ReflectionClass;
use Throwable;

/**
 * Infers a rough response schema from a JsonResource's toArray() by
 * statically scanning its source for array-key assignments.
 *
 * This is a heuristic, not an evaluator: it never executes toArray(), so it
 * cannot know real runtime values. Every field defaults to "string" unless
 * its name or right-hand expression matches a recognizable pattern (id
 * fields, is_/has_ booleans, *_at/date fields, ::collection calls). Review
 * generated schemas for anything with unusual naming.
 */
class ResourceSchemaBuilder
{
    public function build(string $resourceClass): array
    {
        if (! class_exists($resourceClass) || ! method_exists($resourceClass, 'toArray')) {
            return ['type' => 'object'];
        }

        $source = $this->getMethodSource($resourceClass, 'toArray');

        if ($source === null) {
            return ['type' => 'object'];
        }

        $properties = [];

        if (preg_match_all("/'([a-zA-Z0-9_]+)'\s*=>\s*([^,\n]+)/", $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $expr = trim($match[2]);
                $properties[$key] = $this->guessType($key, $expr);
            }
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function guessType(string $key, string $expr): array
    {
        if (preg_match('/^(is_|has_)/', $key) || str_contains($expr, '(bool)') || str_contains($expr, '!!')) {
            return ['type' => 'boolean'];
        }

        if ($key === 'id' || str_ends_with($key, '_id') || str_contains($expr, '(int)')) {
            return ['type' => 'integer'];
        }

        if (preg_match('/(_at|date|created|updated)$/', $key)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (str_contains($expr, '::collection') || str_contains($expr, 'Collection')) {
            return ['type' => 'array', 'items' => ['type' => 'object']];
        }

        return ['type' => 'string'];
    }

    private function getMethodSource(string $class, string $methodName): ?string
    {
        try {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod($methodName);
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();

            if (! $filename || $startLine === false || $endLine === false) {
                return null;
            }

            $lines = file($filename);

            if ($lines === false) {
                return null;
            }

            return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
        } catch (Throwable) {
            return null;
        }
    }
}
