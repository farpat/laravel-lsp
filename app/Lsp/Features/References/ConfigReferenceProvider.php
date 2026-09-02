<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;
use App\Lsp\Support\FileUri;

/**
 * Config keys, declared as an array key of a file in `config/`.
 */
class ConfigReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'configReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '/^\s*[\'"]([\w.-]+)[\'"]\s*=>/';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\bconfig\s*\(\s*[\'"](' . $quoted . ')[\'"]/';
    }

    /**
     * Get the config key declared on the line at the given position.
     *
     * A key is read as `config('app.name')`, so the file it sits in is part of its name.
     * Only a top level key is recognised: a nested one would need the keys it is written
     * under, which reading a single line cannot give.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        $key = parent::nameAt($document, $position);

        if ($key === null) {
            return null;
        }

        return basename(FileUri::of($document->uri)->path(), '.php') . '.' . $key;
    }

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_contains($document->uri, 'config/');
    }
}
