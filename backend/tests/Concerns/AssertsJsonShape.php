<?php

namespace Tests\Concerns;

/**
 * JSON shape assertions in the style of MobileContractShapeTest: a schema is
 * a scalar type string ('string', 'int', 'number', 'bool', 'iso', 'list',
 * 'object', 'enum:a|b', a leading '?' = nullable), an object map, or one of
 * nullable() / listOf() / optional(). Extra keys are allowed (TS ignores
 * them); a missing key or a wrong type fails with the JSON path.
 */
trait AssertsJsonShape
{
    protected static function nullable(mixed $spec): array
    {
        return ['__nullable' => $spec];
    }

    protected static function listOf(mixed $spec): array
    {
        return ['__list' => $spec];
    }

    protected static function optional(mixed $spec): array
    {
        return ['__optional' => $spec];
    }

    protected function assertShape(mixed $value, mixed $spec, string $path = '$'): void
    {
        if (is_string($spec)) {
            $this->assertScalarShape($value, $spec, $path);

            return;
        }
        if (array_key_exists('__optional', $spec)) {
            $this->assertShape($value, $spec['__optional'], $path);

            return;
        }
        if (array_key_exists('__nullable', $spec)) {
            if ($value !== null) {
                $this->assertShape($value, $spec['__nullable'], $path);
            }

            return;
        }
        if (array_key_exists('__list', $spec)) {
            $this->assertIsArray($value, "{$path} must be an array");
            $this->assertTrue(array_is_list($value), "{$path} must be a JSON array, not an object");
            foreach ($value as $i => $item) {
                $this->assertShape($item, $spec['__list'], "{$path}[{$i}]");
            }

            return;
        }

        $this->assertIsArray($value, "{$path} must be an object");
        foreach ($spec as $key => $sub) {
            $optional = is_array($sub) && array_key_exists('__optional', $sub);
            if (!array_key_exists($key, $value)) {
                $this->assertTrue($optional, "{$path}.{$key} is missing (the app reads it)");

                continue;
            }
            $this->assertShape($value[$key], $sub, "{$path}.{$key}");
        }
    }

    private function assertScalarShape(mixed $value, string $type, string $path): void
    {
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return;
            }
            $type = substr($type, 1);
        }
        $this->assertNotNull($value, "{$path} must not be null");

        if (str_starts_with($type, 'enum:')) {
            $allowed = explode('|', substr($type, 5));
            $this->assertContains($value, $allowed, "{$path} = " . json_encode($value) . ' is not one of ' . implode('|', $allowed));

            return;
        }

        match ($type) {
            'string' => $this->assertIsString($value, "{$path} must be a string"),
            'int'    => $this->assertIsInt($value, "{$path} must be an integer"),
            'number' => $this->assertTrue(is_int($value) || is_float($value), "{$path} must be a number"),
            'bool'   => $this->assertIsBool($value, "{$path} must be a boolean"),
            'list'   => $this->assertTrue(is_array($value) && array_is_list($value), "{$path} must be a JSON array"),
            'object' => $this->assertTrue(is_array($value) && ($value === [] || !array_is_list($value)), "{$path} must be an object"),
            'iso'    => $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', (string) $value, "{$path} must be an ISO 8601 date"),
            default  => $this->fail("Unknown schema type {$type} at {$path}"),
        };
    }
}
