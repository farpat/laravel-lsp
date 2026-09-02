<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;

/**
 * Route names, declared with `->name('...')` in a routes file.
 */
class RouteNameReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'routeNameReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/->name\(\s*[\'"]([^\'"]+)[\'"]/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\b(?:route|signedRoute|to_route|temporarySignedRoute)\s*\(\s*[\'"](' . $quoted . ')[\'"]/';
    }

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_contains($document->uri, 'routes/');
    }
}
