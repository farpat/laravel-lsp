<?php

declare(strict_types=1);

namespace App\Lsp\Support;

/**
 * A variable a call site hands to a view, and the expression it is given.
 */
class PassedVariable
{
    /**
     * Create a new passed variable instance.
     *
     * @param  string  $path  the file naming the variable
     * @param  int  $offset  where the name is written in that file
     * @param  string  $value  empty when the call site does not spell the value out, as `compact()` does not
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly int $offset,
        public readonly string $value,
        public readonly int $valueOffset,
    ) {}

    /**
     * Determine if the call site says what the variable holds.
     */
    public function isSpelledOut(): bool
    {
        return $this->value !== '';
    }
}
