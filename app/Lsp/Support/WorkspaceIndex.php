<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;
use Illuminate\Support\Collection;

/**
 * The contents of every searchable file of the project, kept between searches.
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
     * Extensions worth reading.
     *
     * A name does not stay in PHP: a route reached through Ziggy, a translation key read
     * by the front end, are usages like any other, and a declaration that looks unused
     * because its callers are written in TypeScript is worse than no answer at all.
     *
     * @var array<int, string>
     */
    protected const EXTENSIONS = ['php', 'js', 'jsx', 'ts', 'tsx', 'vue'];

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
     * Read the given files again, or drop the ones that are gone.
     *
     * Nothing else is touched: a saved file must be seen by the next search, and reading
     * the whole project again to learn what one file now says costs far more than it
     * gives.
     *
     * @param  array<int, string>  $paths  absolute paths
     */
    public function refresh(array $paths): void
    {
        if ($this->files === null) {
            return;
        }

        foreach ($paths as $path) {
            if (!in_array(pathinfo($path, PATHINFO_EXTENSION), self::EXTENSIONS, true)) {
                $this->refreshFolder($path);

                continue;
            }

            $content = is_file($path) ? file_get_contents($path) : false;

            if (is_string($content)) {
                $this->files[$path] = $content;

                continue;
            }

            unset($this->files[$path]);
        }
    }

    /**
     * Forget what a folder held, and read it again if it is still there.
     *
     * A folder moved or deleted is reported once, as the folder: the files inside it send
     * nothing of their own, so whatever was read under its old name would stay forever.
     */
    protected function refreshFolder(string $path): void
    {
        $prefix = rtrim($path, '/') . '/';

        foreach (array_keys($this->files ?? []) as $file) {
            if (str_starts_with($file, $prefix)) {
                unset($this->files[$file]);
            }
        }

        if (is_dir($path) && !in_array(basename($path), self::EXCLUDED, true)) {
            $this->files = [...$this->files ?? [], ...$this->read($path)];
        }
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
     * Find every match of the given pattern.
     *
     * The needles are looked for first: running a regular expression over a whole project
     * costs far more than rejecting the files that cannot match at all. A file is read as
     * soon as it holds one of them.
     *
     * The pattern is run over the whole file, never line by line: a call is often written
     * across several lines, and `URL::temporarySignedRoute(\n    'checkout.pay',` is a
     * usage like any other. Reading one line at a time made those invisible, so a route
     * only ever reached from a wrapped call was counted as unused.
     *
     * @param  string|array<int, string>  $needles
     * @return Collection<int, array{path: string, line: int, character: int, length: int, text: string}>
     */
    public function search(string $pattern, string|array $needles): Collection
    {
        $matches = collect();
        $needles = is_array($needles) ? $needles : [$needles];

        foreach ($this->files() as $path => $content) {
            if (!$this->holdsAny($content, $needles)) {
                continue;
            }

            if (preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $lines = explode("\n", $content);

            foreach ($found[1] ?? [] as [$value, $offset]) {
                $before = substr($content, 0, $offset);
                $number = substr_count($before, "\n");
                $lineStart = strrpos($before, "\n");

                $matches->push([
                    'path'      => $path,
                    'line'      => $number,
                    'character' => mb_strlen(substr($before, $lineStart === false ? 0 : $lineStart + 1)),
                    'length'    => mb_strlen((string) $value),
                    'text'      => $lines[$number] ?? '',
                ]);
            }
        }

        return $matches;
    }

    /**
     * Determine if the given content holds one of the needles.
     *
     * @param  array<int, string>  $needles
     */
    protected function holdsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read every file worth searching under the given directory, the project by default.
     *
     * @return array<string, string>
     */
    protected function read(?string $base = null): array
    {
        $base ??= $this->project->path();
        $files = [];

        $directories = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directories,
            fn (\SplFileInfo $file): bool => !$file->isDir()
                || !in_array($file->getFilename(), self::EXCLUDED, true),
        );

        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
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
