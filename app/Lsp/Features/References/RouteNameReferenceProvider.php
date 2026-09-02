<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Contracts\ReferenceProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\WorkspaceIndex;

class RouteNameReferenceProvider implements ReferenceProvider
{
    /**
     * Create a new route name reference provider instance.
     */
    public function __construct(
        protected Project $project,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Provide the calls reaching the route name declared at the given position.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!$this->project->boolean('routeNameReferences', true)) {
            return [];
        }

        $name = $this->nameAt($document, $position);

        if ($name === null) {
            return [];
        }

        $quoted = preg_quote($name, '/');

        return $this->index
            ->search("/\\b(?:route|signedRoute|to_route|temporarySignedRoute)\\s*\\(\\s*['\"]({$quoted})['\"]/", $name)
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
     * Get the route name declared on the line at the given position.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        $line = explode("\n", $document->content)[$position['line'] ?? -1] ?? null;

        if (!is_string($line) || preg_match("/->name\\(\\s*['\"]([^'\"]+)['\"]/", $line, $found) !== 1) {
            return null;
        }

        return $found[1];
    }
}
