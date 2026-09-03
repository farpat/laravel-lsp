<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

/**
 * Classes, counted where they are built or dispatched.
 *
 * A job and an event are reached the same way as any other class, so one source answers
 * for all three: what is looked for is `new Thing(`, `dispatch(new Thing)` and
 * `Thing::dispatch`, never a mention in a `use` or a type hint.
 */
class ClassReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'classReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\b(?:dispatch\w*\s*\(\s*new\s+\\\\?[\w\\\\]*?|new\s+\\\\?[\w\\\\]*?|\\\\?[\w\\\\]*?)(' . $quoted . ')\b\s*(?:\(|::dispatch)/';
    }
}
