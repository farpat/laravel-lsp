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
     * Get the names the given document declares, keyed by the line declaring them.
     *
     * @return array<int, string>
     */
    public function declaredLines(Document $document): array
    {
        return array_map(
            fn (array $declaration): string => $declaration['name'],
            $this->declarations($document),
        );
    }

    /**
     * Get how many times each declaration of the document is used, keyed by its line.
     *
     * Every declaration of the file is answered in a single pass over the project: asking
     * line by line means walking every file again for each of them, which a file holding
     * a hundred routes turns into a hundred walks.
     *
     * @return array<int, int>
     */
    public function counts(Document $document): array
    {
        $declared = $this->declaredLines($document);

        if ($declared === []) {
            return [];
        }

        $names = array_values(array_unique($declared));
        $found = array_fill_keys($names, 0);
        $pattern = $this->usage(implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), $names)));

        foreach ($this->index->search($pattern, $names) as $match) {
            $used = mb_substr($match['text'], $match['character'], $match['length']);

            if (isset($found[$used])) {
                $found[$used]++;
            }
        }

        return array_filter(array_map(fn (string $name): int => $found[$name], $declared));
    }

    /**
     * Determine if this source has anything to say about the given document.
     */
    protected function enabledFor(Document $document): bool
    {
        return $this->project->boolean($this->setting(), true) && $this->declaredIn($document);
    }

    /**
     * Get the name declared on the line at the given position.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        return $this->declarations($document)[$position['line'] ?? -1]['name'] ?? null;
    }

    /**
     * Get the declarations written out in the document, keyed by the line declaring them.
     *
     * @return array<int, array{name: string, character: int, length: int}>
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

            $declarations[$line] = [
                'name'      => $name,
                'character' => mb_strlen(substr($text, 0, $offset)),
                'length'    => mb_strlen($name),
            ];
        }

        return $declarations;
    }
}
