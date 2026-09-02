<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

/**
 * Environment variables, declared in a `.env` file.
 */
class EnvReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'envReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/^\s*([A-Z][A-Z0-9_]*)\s*=/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\benv\s*\(\s*[\'"](' . $quoted . ')[\'"]/';
    }
}
