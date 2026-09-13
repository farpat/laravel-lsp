<?php

use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\WorkspaceIndex;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)
    ->afterEach(fn () => exec('rm -rf ' . escapeshellarg(sys_get_temp_dir()) . '/lsp-project-*'))
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// expect()->extend('toBeOne', function () {
//     return $this->toBe(1);
// });

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build a throwaway project holding the given files, keyed by relative path.
 *
 * @param  array<string, string>  $files
 * @return array{project: Project, index: WorkspaceIndex, path: string}
 */
function throwawayProject(array $files): array
{
    $path = sys_get_temp_dir() . '/lsp-project-' . uniqid();

    foreach ($files as $name => $content) {
        @mkdir(dirname($path . '/' . $name), 0777, true);
        file_put_contents($path . '/' . $name, $content);
    }

    $project = new Project(
        FileUri::fromPath($path),
        [],
        new ProjectIndex(app()),
        new ScriptRunner($path, ['php']),
    );

    return ['project' => $project, 'index' => new WorkspaceIndex($project), 'path' => $path];
}
