<?php

declare(strict_types=1);

namespace App\Lsp\Support;

class Position
{
    /**
     * Determine if the given range contains the position.
     *
     * @param  array<string, mixed>|null  $range
     * @param  array<string, mixed>  $position
     */
    public static function inRange(?array $range, array $position): bool
    {
        if (!self::valid($position)) {
            return false;
        }

        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;

        if (!is_array($start) || !is_array($end)) {
            return false;
        }

        if (!self::valid($start) || !self::valid($end)) {
            return false;
        }

        return self::compare($position, $start) >= 0
            && self::compare($position, $end) < 0;
    }

    /**
     * Get the position an offset points at in the given text.
     *
     * @return array{line: int, character: int}
     */
    public static function fromOffset(string $text, int $offset): array
    {
        $before = substr($text, 0, $offset);
        $lineStart = strrpos($before, "\n");

        return [
            'line'      => substr_count($before, "\n"),
            'character' => self::countCharacters(substr($before, $lineStart === false ? 0 : $lineStart + 1)),
        ];
    }

    /**
     * Count the characters of the given text the way LSP does, in UTF-16 code units.
     *
     * An emoji is one character for PHP but two for the editor, so a count in code
     * points puts every range after it on the same line too far to the left.
     */
    public static function countCharacters(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Determine if the value is an LSP position.
     *
     * @param  array<string, mixed>  $position
     */
    protected static function valid(array $position): bool
    {
        return is_int($position['line'] ?? null)
            && is_int($position['character'] ?? null);
    }

    /**
     * Compare two LSP positions.
     *
     * @param  array{line: int, character: int}  $left
     * @param  array{line: int, character: int}  $right
     */
    protected static function compare(array $left, array $right): int
    {
        return $left['line'] <=> $right['line']
            ?: $left['character'] <=> $right['character'];
    }
}
