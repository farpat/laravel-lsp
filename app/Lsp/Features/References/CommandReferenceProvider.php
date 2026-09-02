<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

/**
 * Artisan commands, declared by the `$signature` of a command class.
 */
class CommandReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'commandReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/\$signature\s*=\s*[\'"]([\w:.-]+)/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\b(?:call|queue|command)\s*\(\s*[\'"](' . $quoted . ')(?:\s[^\'"]*)?[\'"]/';
    }
}
