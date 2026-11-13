<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Contracts\ReferenceProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\WorkspaceIndex;

/**
 * References for a name declared on a single line.
 *
 * A route name, a view, a translation key: the declaration is recognised by reading the
 * line the cursor sits on, and the usages by looking the name up across the project.
 */
abstract class DeclarationReferenceProvider implements ReferenceProvider
{
    /**
     * Create a new declaration reference provider instance.
     */
    public function __construct(
        protected Project $project,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Get the setting that turns this source off.
     */
    abstract protected function setting(): string;

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    abstract protected function declaration(): string;

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    abstract protected function usage(string $quoted): string;

    /**
     * Determine if the given file can declare this kind of name.
     */
    protected function declaredIn(Document $document): bool
    {
        return true;
    }

    /**
     * Provide the usages of the declaration at the given position.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!$this->enabledFor($document)) {
            return [];
        }

        $name = $this->nameAt($document, $position);

        if ($name === null) {
            return [];
        }

        return $this->index
            ->search($this->usage(preg_quote($name, '/')), $name)
            ->map(fn (array $match): array => [
                'uri'   => (string) FileUri::fromPath($match['path']),
                'range' => [
                    'start' => ['line' => $match['line'], 'character' => $match['character']],
                    'end'   => ['line' => $match['line'], 'character' => $match['character'] + $match['length']],
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Get the names the given document declares, with where each one is written.
     *
     * @return array<int, array{name: string, line: int, character: int}>
     */
    public function declaredNames(Document $document): array
    {
        return array_map(
            fn (array $declaration): array => [
                'name'      => $declaration['name'],
                'line'      => $declaration['line'],
                'character' => $declaration['character'],
            ],
            $this->declarations($document),
        );
    }

    /**
     * Get how many times each declaration of the document is used, with where it is written.
     *
     * Every declaration of the file is answered in a single pass over the project: asking
     * line by line means walking every file again for each of them, which a file holding
     * a hundred routes turns into a hundred walks.
     *
     * @return array<int, array{line: int, character: int, count: int}>
     */
    public function counts(Document $document): array
    {
        $declared = $this->declaredNames($document);

        if ($declared === []) {
            return [];
        }

        $names = array_values(array_unique(array_column($declared, 'name')));
        $found = array_fill_keys($names, 0);
        $pattern = $this->usage(implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names)));

        foreach ($this->index->search($pattern, $names) as $match) {
            $used = mb_substr($match['text'], $match['character'], $match['length']);

            if (isset($found[$used])) {
                $found[$used]++;
            }
        }

        $counts = [];

        foreach ($declared as $declaration) {
            if ($found[$declaration['name']] > 0) {
                $counts[] = [
                    'line'      => $declaration['line'],
                    'character' => $declaration['character'],
                    'count'     => $found[$declaration['name']],
                ];
            }
        }

        return $counts;
    }

    /**
     * Determine if this source has anything to say about the given document.
     */
    protected function enabledFor(Document $document): bool
    {
        return $this->project->boolean($this->setting(), true) && $this->declaredIn($document);
    }

    /**
     * Get the name declared at the given position.
     *
     * A line can declare more than one name, `'a' => ['b' => 1, 'c' => 2]`: the one
     * written last before the cursor wins, the first of the line when the cursor is before
     * them all.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        $name = null;

        foreach ($this->declarations($document) as $declaration) {
            if ($declaration['line'] !== ($position['line'] ?? -1)) {
                continue;
            }

            if ($name === null || $declaration['character'] <= ($position['character'] ?? 0)) {
                $name = $declaration['name'];
            }
        }

        return $name;
    }

    /**
     * Get the declarations written out in the document, with where each one is written.
     *
     * @return array<int, array{name: string, line: int, character: int, length: int}>
     */
    public function declarations(Document $document): array
    {
        if (!$this->enabledFor($document) || $this->declaration() === '') {
            return [];
        }

        $declarations = [];

        foreach ($document->lines() as $line => $text) {
            if (preg_match($this->declaration(), $text, $found, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            [$name, $offset] = $found[1];

            $declarations[] = [
                'name'      => $name,
                'line'      => $line,
                'character' => mb_strlen(substr($text, 0, $offset)),
                'length'    => mb_strlen($name),
            ];
        }

        return $declarations;
    }
}
