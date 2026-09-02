<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

/**
 * Dispatchable classes, referred to by their class name.
 */
class JobReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'jobReferences';
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
        return '/\b(?:dispatch\w*\s*\(\s*new\s+\\?[\w\\\\]*?|new\s+\\?[\w\\\\]*?|\\?[\w\\\\]*?)(' . $quoted . ')\b\s*(?:\(|::dispatch)/';
    }
}
