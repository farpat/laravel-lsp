<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;

/**
 * The dotted name a Blade file answers to.
 */
class ViewName
{
    /**
     * Get the name of the view the given file is, if it is one.
     *
     * A Blade file declares nothing: the file itself is the view, so the name is read
     * from its path.
     */
    public static function fromUri(Project $project, string $uri): ?string
    {
        $path = FileUri::of($uri)->path();

        if (!str_ends_with($path, '.blade.php')) {
            return null;
        }

        $relative = $project->uri()->relativePath($path);

        foreach (['resources/views/', 'views/'] as $root) {
            if (str_starts_with($relative, $root)) {
                $relative = substr($relative, strlen($root));

                break;
            }
        }

        return str_replace('/', '.', substr($relative, 0, -strlen('.blade.php')));
    }
}
