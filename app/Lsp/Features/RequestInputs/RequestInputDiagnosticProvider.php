<?php

declare(strict_types=1);

namespace App\Lsp\Features\RequestInputs;

use App\Lsp\Contracts\DiagnosticProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class RequestInputDiagnosticProvider implements DiagnosticProvider
{
    /**
     * Create a new request input diagnostic provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide request input diagnostics for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('requestInputDiagnostics', true)) {
            return [];
        }

        return (new RequestInputDocumentMapper($this->project))->diagnostics($document);
    }
}
