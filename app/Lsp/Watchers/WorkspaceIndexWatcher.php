<?php

declare(strict_types=1);

namespace App\Lsp\Watchers;

use App\Lsp\Contracts\FileWatcher;
use App\Lsp\Project;
use App\Lsp\Support\WorkspaceIndex;

/**
 * Keeps the searched file contents in step with the disk.
 *
 * Without it the index answers from whatever the project looked like the first time a
 * search ran, so a usage added afterwards is never counted.
 */
class WorkspaceIndexWatcher implements FileWatcher
{
    /**
     * Create a new workspace index watcher instance.
     */
    public function __construct(
        protected Project $project,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Initialize the workspace index watcher.
     */
    public function initialize(): void
    {
        //
    }

    /**
     * Get the patterns of the files the index reads.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            '**/*.php',
            '**/*.js',
            '**/*.jsx',
            '**/*.ts',
            '**/*.tsx',
            '**/*.vue',
        ];
    }

    /**
     * Handle changed workspace-relative paths.
     *
     * @param  array<int, string>  $changes
     */
    public function onFileChange(array $changes): void
    {
        $this->index->refresh(
            array_map(fn (string $path): string => $this->project->path($path), $changes),
        );
    }
}
