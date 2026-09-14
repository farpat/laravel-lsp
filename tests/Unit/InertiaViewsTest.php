<?php

use App\Lsp\Data\InertiaViews;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

/**
 * Write the given files in a throwaway project and get its Inertia views provider.
 *
 * @param  array<string, string>  $files
 */
function inertiaViewsOf(array $files): InertiaViews
{
    $path = sys_get_temp_dir() . '/lsp-inertia-' . uniqid();

    foreach ($files as $name => $content) {
        @mkdir(dirname($path . '/' . $name), 0777, true);
        file_put_contents($path . '/' . $name, $content);
    }

    return new InertiaViews(new Project(FileUri::fromPath($path), [], new ProjectIndex(app()), new ScriptRunner($path, ['php'])));
}

test('reads the pages from the directory the entry point globs', function () {
    $views = inertiaViewsOf([
        'resources/js/back/app.ts' => <<<'TS'
        import { createInertiaApp } from '@inertiajs/vue3';
        import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

        createInertiaApp({
            resolve: (name) =>
                resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue')),
        });
        TS,
        'resources/js/back/Pages/Site/Fare.vue' => '<template />',
        'resources/js/front/main.ts'            => "import './style.css';",
    ]);

    $parsed = $views->parse([]);

    expect($parsed['page_paths']->all())->toBe(['resources/js/Pages', 'resources/js/back/Pages']);
    expect($parsed['views']->get('Site/Fare'))->toBe([
        'name' => 'Site/Fare',
        'path' => 'resources/js/back/Pages/Site/Fare.vue',
    ]);
});

test('reads the extension and the parent directories from the glob', function () {
    $views = inertiaViewsOf([
        'resources/js/entries/app.tsx'   => "import.meta.glob(['../pages/**/*.tsx', './nothing/*.vue']);",
        'resources/js/pages/Home.tsx'    => 'export default () => null;',
    ]);

    $parsed = $views->parse([]);

    expect($parsed['page_extensions']->all())->toBe(['vue', 'tsx']);
    expect($parsed['views']->keys()->all())->toBe(['Home']);
});

test('keeps the pages the config declares', function () {
    $views = inertiaViewsOf([
        'resources/js/Pages/Welcome.vue' => '<template />',
    ]);

    $parsed = $views->parse(['page_paths' => ['resources/js/Pages']]);

    expect($parsed['views']->keys()->all())->toBe(['Welcome']);
});
