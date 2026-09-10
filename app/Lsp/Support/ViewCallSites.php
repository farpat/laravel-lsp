<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Document;
use App\Lsp\Project;

/**
 * The variables the project hands to a view, read from every call site rendering it.
 *
 * A template never declares the data it renders, so nothing in the file itself says what
 * `$user` is. The controllers rendering it do.
 */
class ViewCallSites
{
    /**
     * Calls able to render a view with data, hence to name its variables.
     *
     * @var array<int, string>
     */
    protected const RENDER_METHODS = [
        'view',
        'make',
        'markdown',
        'include',
        'includeIf',
        'includeWhen',
        'includeUnless',
        'component',
    ];

    /**
     * Brackets opening and closing a nested expression.
     */
    protected const OPENING = '([{';

    protected const CLOSING = ')]}';

    /**
     * Create a new view call sites instance.
     */
    public function __construct(
        protected Project $project,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Get every variable a render call site hands to the given template.
     *
     * @return array<int, PassedVariable>
     */
    public function passedTo(Document $document): array
    {
        $name = ViewName::fromUri($this->project, $document->uri);

        if ($name === null || $name === '') {
            return [];
        }

        $methods = implode('|', array_map(
            fn (string $method): string => preg_quote($method, '/'),
            self::RENDER_METHODS,
        ));

        // The name is often the second argument: `Route::view('/legal', 'legal.notice')`.
        $pattern = '/\b(?:' . $methods . ')\s*[(:]\s*(?:[^()]{0,120},\s*)?[\'"]'
            . preg_quote($name, '/') . '[\'"]/';

        $passed = [];

        foreach ($this->index->files() as $path => $content) {
            if (!str_contains($content, $name)) {
                continue;
            }

            if (preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($found[0] as [$match, $offset]) {
                array_push($passed, ...$this->namesPassedAt(
                    $content,
                    $path,
                    $offset + strlen((string) $match),
                ));
            }
        }

        return $passed;
    }

    /**
     * Get the variables named by the call starting at the given offset.
     *
     * `view('home', $data)` and every `->with(…)` chained onto it both feed the template,
     * and reading only the first would miss half of a common style.
     *
     * @return array<int, PassedVariable>
     */
    protected function namesPassedAt(string $text, string $path, int $nameEnd): array
    {
        $call = $this->untilCallEnd($text, $nameEnd);

        if ($call === null) {
            return [];
        }

        $names = $this->namesIn($call['body'], $path, $nameEnd);
        $from = $call['end'] + 1;

        while (preg_match('/\G\s*(?:->|\?->)\s*with\s*\(/', $text, $chained, PREG_OFFSET_CAPTURE, $from) === 1) {
            $start = $chained[0][1] + strlen((string) $chained[0][0]);
            $with = $this->untilCallEnd($text, $start);

            if ($with === null) {
                break;
            }

            array_push($names, ...$this->namesIn($with['body'], $path, $start));

            // `->with('key', $value)` names its variable as a plain first argument.
            if (preg_match('/^\s*[\'"]([A-Za-z_]\w*)[\'"]\s*,/', $with['body'], $single) === 1) {
                $value = $this->valueAt($with['body'], strlen($single[0]));

                $names[] = new PassedVariable(
                    $single[1],
                    $path,
                    $start + strpos($single[0], $single[1]),
                    $value['value'],
                    $start + $value['offset'],
                );
            }

            $from = $with['end'] + 1;
        }

        return $names;
    }

    /**
     * Get the variable names an argument list makes available.
     *
     * @return array<int, PassedVariable>
     */
    protected function namesIn(string $body, string $path, int $offset): array
    {
        $variables = [];

        if (preg_match_all('/[\'"]([A-Za-z_]\w*)[\'"]\s*=>/', $body, $keys, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== 0) {
            foreach ($keys as [$whole, $name]) {
                $value = $this->valueAt($body, $whole[1] + strlen((string) $whole[0]));

                $variables[] = new PassedVariable(
                    (string) $name[0],
                    $path,
                    $offset + $name[1],
                    $value['value'],
                    $offset + $value['offset'],
                );
            }
        }

        // `compact('user')` names a variable of the calling scope: the call site says
        // which one, never what it holds.
        if (preg_match_all('/\bcompact\s*\(([^)]*)\)/', $body, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== 0) {
            foreach ($calls as [, $arguments]) {
                if (preg_match_all('/[\'"]([A-Za-z_]\w*)[\'"]/', (string) $arguments[0], $names, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($names as [, $name]) {
                    $at = $offset + $arguments[1] + $name[1];

                    $variables[] = new PassedVariable((string) $name[0], $path, $at, '', $at);
                }
            }
        }

        return $variables;
    }

    /**
     * Get the body of the call opened before the given offset, and where it closes.
     *
     * Quotes are tracked so a `)` inside a string does not end the call early.
     *
     * @return array{body: string, end: int}|null
     */
    protected function untilCallEnd(string $text, int $start): ?array
    {
        $depth = 1;
        $quote = null;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $character = $text[$index];

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

                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;

                if ($depth === 0) {
                    return [
                        'body' => substr($text, $start, $index - $start),
                        'end'  => $index,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get the expression starting at the given offset, up to the comma closing it.
     *
     * Nesting and quotes are tracked, so `['a' => ['b', 'c'], …]` keeps its whole array
     * as one value.
     *
     * @return array{value: string, offset: int}
     */
    protected function valueAt(string $body, int $from): array
    {
        $length = strlen($body);
        $start = $from;

        while ($start < $length && preg_match('/\s/', $body[$start]) === 1) {
            $start++;
        }

        $depth = 0;
        $quote = null;
        $end = $start;

        for ($index = $start; $index < $length; $index++) {
            $character = $body[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                $end = $index + 1;

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif (str_contains(self::OPENING, $character)) {
                $depth++;
            } elseif (str_contains(self::CLOSING, $character)) {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                break;
            }

            $end = $index + 1;
        }

        return [
            'value'  => trim(substr($body, $start, $end - $start)),
            'offset' => $start,
        ];
    }
}
