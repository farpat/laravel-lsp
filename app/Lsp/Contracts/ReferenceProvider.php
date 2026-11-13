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
     * Provide the names the given document declares, with where each one is written.
     *
     * @return array<int, array{name: string, line: int, character: int}>
     */
    public function declaredNames(Document $document): array;

    /**
     * Provide the declarations written out in the document: the name, and where it is
     * written. A line can hold more than one, `'a' => ['b' => 1, 'c' => 2]`.
     *
     * A name the file declares without writing it, a view by its own path, is not part
     * of it.
     *
     * @return array<int, array{name: string, line: int, character: int, length: int}>
     */
    public function declarations(Document $document): array;

    /**
     * Provide how many times each declaration of the document is used, with where it is
     * written.
     *
     * @return array<int, array{line: int, character: int, count: int}>
     */
    public function counts(Document $document): array;
}
