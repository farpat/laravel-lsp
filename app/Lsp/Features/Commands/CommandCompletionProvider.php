<?php

declare(strict_types=1);

namespace App\Lsp\Features\Commands;

use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;

class CommandCompletionProvider implements CompletionProvider
{
    /**
     * Create a new command completion provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide command completions for the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!$this->project->boolean('commandCompletion', true)) {
            return [];
        }

        return (new CommandDocumentMapper($this->project))->completions($document, $position);
    }
}
