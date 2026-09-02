<?php

declare(strict_types=1);

namespace App\Lsp\Features\Commands;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class CommandLinkProvider implements LinkProvider
{
    /**
     * Create a new command link provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide command links for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('commandLink', true)) {
            return [];
        }

        return (new CommandDocumentMapper($this->project))->links($document);
    }
}
