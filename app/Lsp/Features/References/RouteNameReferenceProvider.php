<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;
use App\Lsp\Support\Position;
use PhpToken;

/**
 * Route names, declared with `->name('...')` in a routes file.
 *
 * A group can add a prefix to the names inside it: under
 * `Route::name('admin.')->group(...)`, `->name('dashboard')` is the route `admin.dashboard`.
 * So the file is walked token by token, and each name gets the prefixes of its groups.
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
     *
     * Nothing: a name is found by walking the file, not by reading a line.
     */
    protected function declaration(): string
    {
        return '';
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

    /**
     * Get the declarations of the document, keyed by the line declaring them.
     *
     * @return array<int, array{name: string, character: int, length: int}>
     */
    public function declarations(Document $document): array
    {
        if (!$this->enabledFor($document)) {
            return [];
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize($document->content),
            fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $closing = $this->closingParentheses($tokens);
        $groups = [];
        $names = [];

        foreach ($tokens as $index => $token) {
            $method = strtolower($token->text);

            if (!in_array($method, ['name', 'as'], true)
                || !($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                || !($tokens[$index + 1] ?? null)?->is('(')
                || !($tokens[$index + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)
                || !($tokens[$index + 3] ?? null)?->is(')')) {
                continue;
            }

            $name = substr($tokens[$index + 2]->text, 1, -1);
            $group = $this->groupAfter($tokens, $closing, $index + 4);

            if ($group !== null) {
                $groups[] = [...$group, 'prefix' => $name];
            } elseif ($method === 'name') {
                // Past the opening quote: the name is the route, not the string around it.
                $names[] = ['offset' => $tokens[$index + 2]->pos + 1, 'name' => $name];
            }
        }

        $declarations = [];

        foreach ($names as $found) {
            $prefix = '';

            foreach ($groups as $group) {
                if ($group['start'] < $found['offset'] && $found['offset'] < $group['end']) {
                    $prefix .= $group['prefix'];
                }
            }

            $position = Position::fromOffset($document->content, $found['offset']);

            $declarations[$position['line']] = [
                'name'      => $prefix . $found['name'],
                'character' => $position['character'],
                'length'    => mb_strlen($found['name']),
            ];
        }

        return $declarations;
    }

    /**
     * Get the body of the group the call chain goes on to, if it ends with one.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, int>  $closing
     * @return array{start: int, end: int}|null the offsets of the parentheses of `group(...)`
     */
    protected function groupAfter(array $tokens, array $closing, int $index): ?array
    {
        while (($tokens[$index] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && ($tokens[$index + 2] ?? null)?->is('(')
            && isset($closing[$index + 2])) {
            $end = $closing[$index + 2];

            if (strtolower($tokens[$index + 1]->text) === 'group') {
                return ['start' => $tokens[$index + 2]->pos, 'end' => $tokens[$end]->pos];
            }

            $index = $end + 1;
        }

        return null;
    }

    /**
     * Get the index of the closing parenthesis of each opening one.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, int>
     */
    protected function closingParentheses(array $tokens): array
    {
        $open = [];
        $closing = [];

        foreach ($tokens as $index => $token) {
            if ($token->is('(')) {
                $open[] = $index;
            } elseif ($token->is(')') && $open !== []) {
                $closing[array_pop($open)] = $index;
            }
        }

        return $closing;
    }
}
