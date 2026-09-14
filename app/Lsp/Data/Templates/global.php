<?php

class LspHelper
{
    public static function relativePath($path)
    {
        $path = realpath($path) ?: (string) $path;
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR);

        if ($path === $basePath) {
            return '';
        }

        if (!str_starts_with($path, $basePath . DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return substr($path, strlen($basePath) + 1);
    }

    public static function isVendor($path)
    {
        return str_contains($path, base_path('vendor'));
    }

    public static function propertyDefault(ReflectionProperty $property, ?ReflectionParameter $parameter = null): array
    {
        if ($property->hasDefaultValue()) {
            return ['default' => $property->getDefaultValue()];
        }

        if ($parameter?->isDefaultValueAvailable()) {
            return ['default' => $parameter->getDefaultValue()];
        }

        return [];
    }

    public static function formatDefaultValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value)           => 'array(...)',
            $value instanceof UnitEnum => get_class($value) . '::' . $value->name,
            $value instanceof Closure  => 'Closure',
            is_object($value)          => get_class($value),
            is_string($value)          => var_export($value, true),
            is_null($value)            => 'null',
            is_bool($value)            => $value ? 'true' : 'false',
            default                    => $value,
        };
    }
}
