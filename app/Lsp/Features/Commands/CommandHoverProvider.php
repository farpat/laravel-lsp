<?php

declare(strict_types=1);

namespace App\Lsp\Features\Commands;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class CommandHoverProvider implements HoverProvider
{
    /**
     * Create a new command hover provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide command hovers for the given document.
     *
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
    {
        if (!$this->project->boolean('commandHover', true)) {
            return null;
        }

        return (new CommandDocumentMapper($this->project))->hover($document, $position);
    }
}
