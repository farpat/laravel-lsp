<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;
use Illuminate\Support\Collection;

/**
 * The contents of every PHP file of the project, kept between searches.
 *
 * Reading them again on each search is what makes a workspace-wide search feel slow, and
 * a reference search reads the same files over and over.
 */
class WorkspaceIndex
{
    /**
     * File contents keyed by absolute path.
     *
     * @var array<string, string>|null
     */
    protected ?array $files = null;

    /**
     * Directories never worth reading.
     *
     * @var array<int, string>
     */
    protected const EXCLUDED = ['vendor', 'node_modules', 'storage', 'public'];

    /**
     * Create a new workspace index instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get the indexed files.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        return $this->files ??= $this->read();
    }

    /**
     * Forget what was read, so the next search sees the current files.
     */
    public function flush(): void
    {
        $this->files = null;
    }

    /**
     * Get the lines of the given file.
     *
     * @return array<int, string>
     */
    public function lines(string $path): array
    {
        return explode("\n", $this->files()[$path] ?? '');
    }

    /**
     * Find every line matching the given pattern.
     *
     * The needle is looked for first: running a regular expression over a whole project
     * costs far more than rejecting the files that cannot match at all.
     *
     * @return Collection<int, array{path: string, line: int, character: int, length: int, text: string}>
     */
    public function search(string $pattern, string $needle): Collection
    {
        $matches = collect();

        foreach ($this->files() as $path => $content) {
            if (!str_contains($content, $needle)) {
                continue;
            }

            foreach (explode("\n", $content) as $number => $text) {
                if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($found[1] ?? [] as [$value, $offset]) {
                    $matches->push([
                        'path'      => $path,
                        'line'      => $number,
                        'character' => mb_strlen(substr($text, 0, $offset)),
                        'length'    => mb_strlen((string) $value),
                        'text'      => $text,
                    ]);
                }
            }
        }

        return $matches;
    }

    /**
     * Read every project PHP file.
     *
     * @return array<string, string>
     */
    protected function read(): array
    {
        $base = $this->project->path();
        $files = [];

        $directories = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directories,
            fn (\SplFileInfo $file): bool => !$file->isDir()
                || !in_array($file->getFilename(), self::EXCLUDED, true),
        );

        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (is_string($content)) {
                $files[$file->getPathname()] = $content;
            }
        }

        return $files;
    }
}
