<?php

declare(strict_types=1);

namespace App\Lsp\Contracts;

use App\Lsp\Document;

interface ReferenceProvider
{
    /**
     * Provide the locations where the declaration at the given position is used.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array;
}
