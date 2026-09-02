<?php

declare(strict_types=1);

namespace App\Lsp\Features\References;

use App\Lsp\Document;
use App\Lsp\Support\FileUri;

/**
 * Views, referred to by the dotted name of a Blade file.
 */
class ViewNameReferenceProvider extends DeclarationReferenceProvider
{
    /**
     * Get the setting that turns this source off.
     */
    protected function setting(): string
    {
        return 'viewNameReferences';
    }

    /**
     * Get the pattern recognising a declaration, capturing the name.
     */
    protected function declaration(): string
    {
        return '';
    }

    /**
     * Get the pattern recognising a usage of the given name, capturing it.
     */
    protected function usage(string $quoted): string
    {
        // `Route::view('/legal', 'legal.notice')` names the view second, so whatever
        // comes before it inside the call is skipped.
        return '/\b(?:view|View::make|@include|@extends|@each)\s*[:(]\s*(?:[^()]{0,120},\s*)?[\'\"](' . $quoted . ')[\'\"]/';
    }

    /**
     * Get the name of the view the document is, if it is one.
     *
     * A Blade file declares nothing: the file itself is the declaration, so the name is
     * read from its path rather than from the line under the cursor.
     *
     * @param  array<string, mixed>  $position
     */
    protected function nameAt(Document $document, array $position): ?string
    {
        $path = FileUri::of($document->uri)->path();

        if (!str_ends_with($path, '.blade.php')) {
            return null;
        }

        $relative = $this->project->uri()->relativePath($path);

        foreach (['resources/views/', 'views/'] as $root) {
            if (str_starts_with($relative, $root)) {
                $relative = substr($relative, strlen($root));

                break;
            }
        }

        return str_replace('/', '.', substr($relative, 0, -strlen('.blade.php')));
    }
}
