<?php

declare(strict_types=1);

namespace App\Lsp\Support;

/**
 * What a PHP source says can be read on the values it declares.
 *
 * The project holds the answer in plain text: a template reaching for `$order->total`
 * needs the class of `$order`, and that class is a file like any other.
 */
class PhpMembers
{
    /**
     * Modifiers a member declaration may carry.
     */
    protected const MODIFIERS = '(?:final|abstract|public|private|protected|static|readonly)';

    /**
     * Create a new PHP members instance.
     */
    public function __construct(
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Get the members a template may reach through a value of the given source.
     *
     * Anything private or protected is left out: offering it would only lead to a fatal
     * error at render time.
     *
     * @return array<int, array{name: string, kind: int, detail: string}>
     */
    public function publicMembersOf(string $source): array
    {
        $members = [];
        $method = '/\n[ \t]*((?:' . self::MODIFIERS . '\s+)*)function\s+(\w+)\s*\(([^)]*)\)(?:\s*:\s*([\w\\\\|?\[\]]+))?/';

        if (preg_match_all($method, $source, $methods, PREG_SET_ORDER) !== 0) {
            foreach ($methods as $found) {
                [, $modifiers, $name, $parameters] = $found;

                if ($this->isHidden($modifiers)) {
                    continue;
                }

                // A constructor is never called on the variable, but it declares properties.
                if (str_starts_with($name, '__')) {
                    array_push($members, ...$this->promotedIn($parameters));

                    continue;
                }

                // A method without a return type stops the match early, so the group is
                // missing rather than empty.
                $returns = $found[4] ?? '';

                $members[] = [
                    'name'   => $name,
                    'kind'   => 2,
                    'detail' => '(' . trim($parameters) . ')' . ($returns === '' ? '' : ': ' . $returns),
                ];
            }
        }

        $property = '/\n[ \t]*((?:' . self::MODIFIERS . '\s+)+)([\w\\\\|?]+\s+)?\$(\w+)/';

        if (preg_match_all($property, $source, $properties, PREG_SET_ORDER) !== 0) {
            foreach ($properties as [, $modifiers, $type, $name]) {
                if ($this->isHidden($modifiers) || !str_contains($modifiers, 'public')) {
                    continue;
                }

                $members[] = [
                    'name'   => $name,
                    'kind'   => 10,
                    'detail' => trim($type),
                ];
            }
        }

        if (preg_match_all('/\n[ \t]*((?:final|public|private|protected)\s+)*const\s+(?:[\w\\\\|?]+\s+)?(\w+)/', $source, $constants, PREG_SET_ORDER) !== 0) {
            foreach ($constants as [, $modifiers, $name]) {
                if ($this->isHidden($modifiers)) {
                    continue;
                }

                $members[] = [
                    'name'   => $name,
                    'kind'   => 21,
                    'detail' => 'const',
                ];
            }
        }

        return $members;
    }

    /**
     * Get the file declaring the given type, found by name.
     *
     * @return array{path: string, source: string}|null
     */
    public function findDeclaration(string $type): ?array
    {
        $parts = explode('\\', ltrim($type, '\\'));
        $shortName = array_pop($parts);

        if ($shortName === null || $shortName === '') {
            return null;
        }

        $namespace = implode('\\', $parts);
        $declares = '/\b(?:class|interface|trait|enum)\s+' . preg_quote($shortName, '/') . '\b/';
        $inNamespace = '/namespace\s+' . preg_quote($namespace, '/') . '\s*[;{]/';

        foreach ($this->index->files() as $path => $source) {
            if (preg_match($declares, $source) !== 1) {
                continue;
            }

            if ($namespace !== '' && preg_match($inNamespace, $source) !== 1) {
                continue;
            }

            return ['path' => $path, 'source' => $source];
        }

        return null;
    }

    /**
     * Get the file declaring a function of the given name, and where it declares it.
     *
     * A weaker answer than a full analysis, since two classes may share a method name,
     * but one the index already holds.
     *
     * @return array{source: string, offset: int}|null
     */
    public function findFunction(string $name): ?array
    {
        $declares = '/\bfunction\s+' . preg_quote($name, '/') . '\s*\(/';

        foreach ($this->index->files() as $source) {
            if (preg_match($declares, $source, $found, PREG_OFFSET_CAPTURE) === 1) {
                return ['source' => $source, 'offset' => $found[0][1]];
            }
        }

        return null;
    }

    /**
     * Get the array a function hands back, when it hands back a literal.
     */
    public function returnedArrayIn(string $source, int $declaredAt): ?string
    {
        $body = strpos($source, '{', $declaredAt);

        if ($body === false) {
            return null;
        }

        $returned = strpos($source, 'return', $body);

        if ($returned === false) {
            return null;
        }

        $opened = strpos($source, '[', $returned);

        return $opened === false ? null : $this->untilClosed($source, $opened);
    }

    /**
     * Get the keys of an array literal, nested ones left out.
     *
     * `$pricing['…']` reads the array it was handed, not the arrays inside it.
     *
     * @return array<int, string>
     */
    public function arrayKeysIn(string $literal): array
    {
        $keys = [];
        $depth = 0;
        $quote = null;
        $pending = null;
        $length = strlen($literal);

        for ($index = 0; $index < $length; $index++) {
            $character = $literal[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $opened = strrpos(substr($literal, 0, $index), $quote);
                    $text = $opened === false ? '' : substr($literal, $opened + 1, $index - $opened - 1);

                    $pending = $depth === 0 ? $text : null;
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (str_contains('([{', $character)) {
                $depth++;
            } elseif (str_contains(')]}', $character)) {
                $depth--;
            } elseif ($character === '=' && ($literal[$index + 1] ?? '') === '>') {
                if ($depth === 0 && $pending !== null) {
                    $keys[] = $pending;
                }

                $pending = null;
            } elseif ($character === ',' && $depth === 0) {
                $pending = null;
            }
        }

        return $keys;
    }

    /**
     * Get the balanced literal opened at the given offset.
     */
    protected function untilClosed(string $source, int $from): ?string
    {
        $open = $source[$from];
        $close = $open === '[' ? ']' : ')';
        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($index = $from; $index < $length; $index++) {
            $character = $source[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === $open) {
                $depth++;
            } elseif ($character === $close) {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $from + 1, $index - $from - 1);
                }
            }
        }

        return null;
    }

    /**
     * Get the properties a promoted constructor declares.
     *
     * `__construct(public readonly int $seats)` declares a property, not a parameter.
     *
     * @return array<int, array{name: string, kind: int, detail: string}>
     */
    protected function promotedIn(string $parameters): array
    {
        $promoted = '/((?:' . self::MODIFIERS . '\s+)+)([\w\\\\|?]+\s+)?\$(\w+)/';

        if (preg_match_all($promoted, $parameters, $found, PREG_SET_ORDER) === 0) {
            return [];
        }

        $members = [];

        foreach ($found as [, $modifiers, $type, $name]) {
            if (!str_contains($modifiers, 'public')) {
                continue;
            }

            $members[] = [
                'name'   => $name,
                'kind'   => 10,
                'detail' => trim($type),
            ];
        }

        return $members;
    }

    /**
     * Determine if the given modifiers keep a member out of a template.
     */
    protected function isHidden(string $modifiers): bool
    {
        return str_contains($modifiers, 'private') || str_contains($modifiers, 'protected');
    }
}
