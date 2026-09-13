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

    /**
     * Provide the names the given document declares, keyed by the line declaring them.
     *
     * @return array<int, string>
     */
    public function declaredLines(Document $document): array;

    /**
     * Provide the declarations written out in the document, keyed by the line declaring
     * them: the name, and where it is written on that line.
     *
     * A name the file declares without writing it, a view by its own path, is not part
     * of it.
     *
     * @return array<int, array{name: string, character: int, length: int}>
     */
    public function declarations(Document $document): array;

    /**
     * Provide how many times each declaration of the document is used, keyed by its line.
     *
     * @return array<int, int>
     */
    public function counts(Document $document): array;
}
