<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

/**
 * Middleware, referred to by the alias a route declares.
 */
class MiddlewareReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'middlewareReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/[\'\"]([\w.-]+)[\'\"]\s*=>\s*\\\\?[\w\\\\]+::class/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\b(?:middleware|withoutMiddleware)\s*\(\s*\[?\s*[\'\"](' . $quoted . ')(?::[^\'\"]*)?[\'\"]/';
    }
}
