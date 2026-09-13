<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;

/**
 * A link on every declaration, opening its usages.
 *
 * A declaration has nowhere to go: it is the place. What a click on it can open is the
 * list of what reads it, the way PhpStorm does, and that is the command the usages lens
 * already runs. The link is the same command, so a click on the name and a click on the
 * count above it read the same.
 */
class DeclarationLinkProvider implements LinkProvider
{
    /**
     * Create a new declaration link provider instance.
     */
    public function __construct(
        protected Project $project,
        protected FeatureRegistry $features,
    ) {}

    /**
     * Provide a link to the usages of every declaration of the document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('usagesLink', true)) {
            return [];
        }

        $links = [];

        foreach ($this->features->references() as $provider) {
            foreach ($provider->declarations($document) as $line => $declaration) {
                $links[] = $this->link($document, $line, $declaration);
            }
        }

        return $links;
    }

    /**
     * Get the link opening the usages of the given declaration.
     *
     * @param  array{name: string, character: int, length: int}  $declaration
     * @return array<string, mixed>
     */
    protected function link(Document $document, int $line, array $declaration): array
    {
        $arguments = [$document->uri, ['line' => $line, 'character' => $declaration['character']]];

        return [
            'range' => [
                'start' => ['line' => $line, 'character' => $declaration['character']],
                'end'   => ['line' => $line, 'character' => $declaration['character'] + $declaration['length']],
            ],
            'target'  => 'command:laravel.showUsages?' . rawurlencode((string) json_encode($arguments, JSON_UNESCAPED_SLASHES)),
            'tooltip' => 'Show usages',
        ];
    }
}
