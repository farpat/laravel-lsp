<?php

declare(strict_types=1);

namespace App\Lsp\Features\RequestInputs;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class RequestInputLinkProvider implements LinkProvider
{
    /**
     * Create a new request input link provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide request input links for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('requestInputLink', true)) {
            return [];
        }

        return (new RequestInputDocumentMapper($this->project))->links($document);
    }
}
