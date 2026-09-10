<?php

declare(strict_types=1);

namespace App\Lsp\Contracts;

use App\Lsp\Document;

interface DefinitionProvider
{
    /**
     * Provide the definitions of what sits at the given position.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array;
}
