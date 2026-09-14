<?php

declare(strict_types=1);

namespace App\Lsp\Features\Eloquent;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class EloquentAttributeLinkProvider implements LinkProvider
{
    /**
     * Create a new Eloquent attribute link provider instance.
     */
    public function __construct(
        protected Project $project,
        protected AttributeDeclarations $declarations,
    ) {}

    /**
     * Provide Eloquent attribute links for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('eloquentAttributeLink', true)) {
            return [];
        }

        return (new EloquentAttributeDocumentMapper($this->project, $this->declarations))->links($document);
    }
}
