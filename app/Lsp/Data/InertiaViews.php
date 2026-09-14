<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class InertiaViews implements DataProvider
{
    /**
     * Create a new inertia views provider instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the inertia template to run.
     */
    public function template(): string
    {
        return file_get_contents(__DIR__ . '/Templates/inertia.php') ?: '';
    }

    /**
     * Parse the raw inertia config data.
     *
     * @param  array<string, mixed>  $data
     * @return array{views: Collection<string, array<string, string>>, page_paths: Collection<int, string>, page_extensions: Collection<int, string>}
     */
    public function parse(array $data): array
    {
        $globbed = $this->globbedPages();

        $paths = $this->normalizePagePaths($data)->merge($globbed->pluck('path'))->unique()->values();
        $extensions = $this->normalizePageExtensions($data)->merge($globbed->pluck('extension'))->unique()->values();

        return [
            'views' => $paths
                ->flatMap(fn (string $path): Collection => $this->discoverViews($path, $extensions))
                ->keyBy('name'),
            'page_paths'      => $paths,
            'page_extensions' => $extensions,
        ];
    }

    /**
     * Get data.
     *
     * @return array{views: Collection<string, array<string, string>>, page_paths: Collection<int, string>, page_extensions: Collection<int, string>}
     */
    public function get(): array
    {
        $data = $this->project->scripts->json($this->template());

        return $this->parse(is_array($data) ? $data : []);
    }

    /**
     * Get inertia-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            'resources/js/Pages/{*,**/*}',
            'resources/js/pages/{*,**/*}',
            'resources/js/{*,**/*}.{vue,jsx,tsx,svelte,js,ts}',
            'config/{,*,**/*}.php',
        ];
    }

    /**
     * Get the page directories the front-end entry points glob, with their extension.
     *
     * A page is resolved in the browser, from whatever `import.meta.glob()` the entry
     * point hands to Inertia, so that glob is where the pages are, whatever the config
     * says: `resources/js/back/app.ts` globbing its `Pages` directory reads its pages in
     * `resources/js/back/Pages`.
     *
     * @return Collection<int, array{path: string, extension: string}>
     */
    protected function globbedPages(): Collection
    {
        $scripts = $this->project->path('resources/js');

        if (!is_dir($scripts)) {
            return collect();
        }

        return collect(Finder::create()->files()->in($scripts)->name('/\.(js|ts|jsx|tsx)$/')->exclude('node_modules'))
            ->flatMap(function (SplFileInfo $script): array {
                preg_match_all('/import\.meta\.glob\s*(?:<[^>]*>)?\s*\(([^)]*)\)/', (string) file_get_contents($script->getPathname()), $calls);

                $pages = [];

                foreach ($calls[1] as $arguments) {
                    preg_match_all('/[\'"]([^\'"]*\*[^\'"]*)[\'"]/', $arguments, $globs);

                    foreach ($globs[1] as $glob) {
                        $page = $this->globbedPage($script, $glob);

                        if ($page !== null) {
                            $pages[] = $page;
                        }
                    }
                }

                return $pages;
            })
            ->values();
    }

    /**
     * Get the page directory and extension a glob written in the given script points to.
     *
     * @return array{path: string, extension: string}|null
     */
    protected function globbedPage(SplFileInfo $script, string $glob): ?array
    {
        if (preg_match('/^(.*?)\/?\*.*\.(\w+)$/', $glob, $parts) !== 1) {
            return null;
        }

        [, $directory, $extension] = $parts;

        $path = str_starts_with($directory, '/')
            ? $directory
            : 'resources/js/' . str_replace('\\', '/', $script->getRelativePath()) . '/' . $directory;

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        $path = implode('/', $segments);

        return is_dir($this->project->path($path)) ? ['path' => $path, 'extension' => $extension] : null;
    }

    /**
     * Normalize Inertia page paths.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, string>
     */
    protected function normalizePagePaths(array $data): Collection
    {
        $paths = collect($data['page_paths'] ?? [])
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->values();

        return $paths->isEmpty() ? collect(['resources/js/Pages']) : $paths;
    }

    /**
     * Normalize Inertia page extensions.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, string>
     */
    protected function normalizePageExtensions(array $data): Collection
    {
        $extensions = collect($data['page_extensions'] ?? [])
            ->filter(fn (mixed $extension): bool => is_string($extension) && $extension !== '')
            ->map(fn (string $extension): string => ltrim($extension, '.'))
            ->values();

        return $extensions->isEmpty() ? collect(['vue']) : $extensions;
    }

    /**
     * Discover Inertia views under a page path.
     *
     * @param  Collection<int, string>  $extensions
     * @return Collection<int, array<string, string>>
     */
    protected function discoverViews(string $path, Collection $extensions): Collection
    {
        $absolute = $this->project->path($path);

        if (!is_dir($absolute)) {
            return collect();
        }

        return collect(Finder::create()->files()->in($absolute))
            ->filter(fn (SplFileInfo $file): bool => $extensions->contains($file->getExtension()))
            ->map(function (SplFileInfo $file) use ($path): array {
                $relative = $file->getRelativePathname();
                $name = preg_replace('/\.[^.]+$/', '', $relative) ?: $relative;

                return [
                    'name' => str_replace('\\', '/', $name),
                    'path' => trim($path, '/') . '/' . str_replace('\\', '/', $relative),
                ];
            })
            ->values();
    }
}
