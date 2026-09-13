<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;

/**
 * Config keys, declared as an array key of a file in `config/`.
 */
class ConfigReferenceProvider extends ArrayKeyReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'configReferences';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        return '/\bconfig\s*\(\s*[\'"](' . $quoted . ')[\'"]/';
    }

    /**
     * Get the first segment of the keys the document declares, from its path.
     *
     * A key is read as `config('app.name')`: the file is the first segment, and a file
     * in a sub directory of `config/` is read through it, `config('foo.bar.key')`.
     */
    protected function prefix(Document $document): ?string
    {
        $segments = $this->segmentsUnder($document, 'config');

        return $segments === null ? null : implode('.', $segments);
    }

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_contains($document->uri, 'config/');
    }
}
