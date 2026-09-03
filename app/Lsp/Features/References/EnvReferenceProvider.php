<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;
use App\Lsp\Support\FileUri;

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

    /**
     * Determine if the given file can declare this kind of name.
     *
     * `NAME = value` is a shape any file can hold, a heredoc or a piece of documentation
     * included, so the file itself has to say that the line is a declaration. The variants
     * of the file are all accepted: `.env`, `.env.local`, `.env.example`.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_starts_with(basename(FileUri::of($document->uri)->path()), '.env');
    }
}
