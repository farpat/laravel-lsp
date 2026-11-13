<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;
use App\Lsp\Support\Position;
use PhpToken;

/**
 * Keys of a PHP file returning an array, read as `file.key.nested`.
 *
 * A config key and a translation key are written the same way: the file gives the first
 * segment, and every array the key sits under gives one more. A line on its own cannot
 * tell `'host'` under `mailers.smtp` from `'host'` under `redis`, so the file is walked
 * token by token, keeping the keys opened above.
 */
abstract class ArrayKeyReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the first segment of the keys the document declares, from its path.
     */
    abstract protected function prefix(Document $document): ?string;

    /**
     * Get the pattern recognising a declaration, capturing the name.
     *
     * Nothing: a key is found by walking the file, not by reading a line.
     */
    protected function declaration(): string
    {
        return '';
    }

    /**
     * Get the declarations of the document, with where each one is written.
     *
     * @return array<int, array{name: string, line: int, character: int, length: int}>
     */
    public function declarations(Document $document): array
    {
        if (!$this->enabledFor($document)) {
            return [];
        }

        $prefix = $this->prefix($document);

        if ($prefix === null) {
            return [];
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($document->content),
            fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $declarations = [];
        $keys = [];
        $depth = -1;

        foreach ($tokens as $index => $token) {
            // An attribute opens with `#[` and closes with a plain `]`.
            if ($token->is(['[', T_ATTRIBUTE])) {
                $depth++;

                continue;
            }

            if ($token->is(']')) {
                $depth--;
                $keys = array_slice($keys, 0, $depth + 1);

                continue;
            }

            if ($depth < 0 || !$token->is(T_CONSTANT_ENCAPSED_STRING) || !($tokens[$index + 1] ?? null)?->is(T_DOUBLE_ARROW)) {
                continue;
            }

            $keys[$depth] = trim($token->text, '\'"');
            // Past the opening quote: the name is the key, not the string around it.
            $position = Position::fromOffset($document->content, $token->pos + 1);

            $declarations[] = [
                'name'      => $prefix . '.' . implode('.', $keys),
                'line'      => $position['line'],
                'character' => $position['character'],
                'length'    => Position::countCharacters($token->text) - 2,
            ];
        }

        return $declarations;
    }

    /**
     * Get the path of the document under the given directory, without its extension.
     *
     * @return array<int, string>|null the path segments, null when the file is elsewhere
     */
    protected function segmentsUnder(Document $document, string $directory): ?array
    {
        $path = parse_url($document->uri, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $start = strrpos($path, "/{$directory}/");

        if ($start === false) {
            return null;
        }

        $relative = substr($path, $start + strlen($directory) + 2);

        return explode('/', preg_replace('/\.php$/', '', $relative) ?? $relative);
    }
}
