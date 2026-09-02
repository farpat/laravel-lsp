<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;

/**
 * Translation keys, declared as an array key of a file in `lang/`.
 */
class TranslationKeyReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'translationKeyReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/^\s*[\'\"]([\w.-]+)[\'\"]\s*=>/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\b(?:__|trans|trans_choice|@lang)\s*\(?\s*[\'\"]([\w.-]*' . $quoted . '[\w.-]*)[\'\"]/';
    }

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_contains($document->uri, 'lang/');
    }
}
