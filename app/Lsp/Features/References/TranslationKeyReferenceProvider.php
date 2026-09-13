<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;

/**
 * Translation keys, declared as an array key of a file in `lang/`.
 */
class TranslationKeyReferenceProvider extends ArrayKeyReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'translationKeyReferences';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     *
     * `@lang` starts with a symbol, so no word boundary can sit before it.
     */
    protected function usage(string $quoted): string
    {
        return '/(?:\b(?:__|trans|trans_choice|Lang::(?:get|has|hasForLocale|choice|string))|@(?:lang|choice))\s*\(\s*[\'"](' . $quoted . ')[\'"]/';
    }

    /**
     * Get the first segment of the keys the document declares, from its path.
     *
     * The locale directory is not part of a key: `lang/fr/notifications.php` is read as
     * `__('notifications.key')`. A file in a deeper directory keeps the slash,
     * `__('mails/welcome.subject')`, and a package translation carries its namespace,
     * `lang/vendor/shop/fr/cart.php` being read as `__('shop::cart.key')`.
     */
    protected function prefix(Document $document): ?string
    {
        $segments = $this->segmentsUnder($document, 'lang');

        if ($segments === null) {
            return null;
        }

        $namespace = '';

        if ($segments[0] === 'vendor') {
            $namespace = ($segments[1] ?? '') . '::';
            $segments = array_slice($segments, 2);
        }

        // The locale directory, then at least the file.
        if (count($segments) < 2) {
            return null;
        }

        return $namespace . implode('/', array_slice($segments, 1));
    }

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return str_contains($document->uri, 'lang/');
    }
}
