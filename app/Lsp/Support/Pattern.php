<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use Symfony\Component\Finder\Glob;

class Pattern
{
    /**
     * The compiled expressions, keyed by file watcher pattern.
     *
     * @var array<string, string>
     */
    protected static array $expressions = [];

    /**
     * Determine if the given path matches a file watcher pattern.
     */
    public static function matches(string $path, string $pattern): bool
    {
        if (self::isFolder($path)) {
            return self::reaches($pattern, $path);
        }

        return preg_match(self::expression($pattern), self::normalize($path)) === 1;
    }

    /**
     * Determine if the given path names a folder rather than a file.
     *
     * A folder moved or deleted is reported without the files it held, and a watcher
     * cannot tell what those were: a path without an extension is taken for one.
     */
    protected static function isFolder(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) === '';
    }

    /**
     * Determine if the pattern could match a file inside the given folder.
     *
     * The literal part of the pattern, before its first wildcard, is the directory it
     * watches: `app/Http/Controllers/**\/*.php` watches `app/Http/Controllers`. A folder
     * above or below it may hold files the pattern matches.
     */
    protected static function reaches(string $pattern, string $folder): bool
    {
        $literal = substr(self::normalize($pattern), 0, strcspn(self::normalize($pattern), '*{?['));
        $watched = rtrim(substr($literal, 0, (int) strrpos($literal, '/')), '/') . '/';
        $folder = rtrim(self::normalize($folder), '/') . '/';

        return str_starts_with($folder, $watched) || str_starts_with($watched, $folder);
    }

    /**
     * Get the compiled expression for the given file watcher pattern.
     */
    protected static function expression(string $pattern): string
    {
        return self::$expressions[$pattern] ??= Glob::toRegex(self::normalize($pattern));
    }

    /**
     * Determine if the given path matches any file watcher pattern.
     *
     * @param  array<int, string>  $patterns
     */
    public static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if any path matches any file watcher pattern.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $patterns
     */
    public static function matchesAnyPath(array $paths, array $patterns): bool
    {
        foreach ($paths as $path) {
            if (self::matchesAny($path, $patterns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize paths and patterns for glob matching.
     */
    protected static function normalize(string $value): string
    {
        return '/' . ltrim(str_replace('\\', '/', $value), '/');
    }
}
