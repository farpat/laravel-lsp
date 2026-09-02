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
        if (!$this->project->boolean($this->setting(), true) || !$this->declaredIn($document)) {
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
     * Get the name declared on the line at the given position.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        $line = explode("\n", $document->content)[$position['line'] ?? -1] ?? null;

        if (!is_string($line) || preg_match($this->declaration(), $line, $found) !== 1) {
            return null;
        }

        return $found[1];
    }
}
