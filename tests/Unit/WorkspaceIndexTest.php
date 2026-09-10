<?php

use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\WorkspaceIndex;

/**
 * Build an index over a throwaway project holding the given files.
 *
 * @param  array<string, string>  $files
 */
function workspaceIndex(array $files): WorkspaceIndex
{
    $path = sys_get_temp_dir() . '/lsp-workspace-' . uniqid();

    mkdir($path);

    foreach ($files as $name => $content) {
        file_put_contents($path . '/' . $name, $content);
    }

    $project = new Project(
        FileUri::fromPath($path),
        [],
        new ProjectIndex(app()),
        new ScriptRunner($path, ['php']),
    );

    return new WorkspaceIndex($project);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/lsp-workspace-*') as $directory) {
        array_map('unlink', glob($directory . '/*'));
        rmdir($directory);
    }
});

$routeUsage = '/\b(?:route|signedRoute|to_route|temporarySignedRoute)\s*\(\s*[\'"](checkout\.pay)[\'"]/';

test('finds a call written across several lines', function () use ($routeUsage) {
    $index = workspaceIndex([
        'mail.php' => <<<'PHP'
        <?php

        $url = URL::temporarySignedRoute(
            'checkout.pay',
            now(),
        );
        PHP,
    ]);

    $matches = $index->search($routeUsage, 'checkout.pay');

    expect($matches)->toHaveCount(1);
    expect($matches->first())->toMatchArray([
        'line'      => 3,
        'character' => 5,
        'length'    => 12,
        'text'      => "    'checkout.pay',",
    ]);
});

test('counts every call of a line, not the line itself', function () use ($routeUsage) {
    $index = workspaceIndex([
        'view.php' => '<a href="{{ route(\'checkout.pay\') }}">{{ route(\'checkout.pay\') }}</a>',
    ]);

    expect($index->search($routeUsage, 'checkout.pay'))->toHaveCount(2);
});

test('ignores a file that never mentions the name', function () use ($routeUsage) {
    $index = workspaceIndex([
        'other.php' => '<?php route(\'checkout.complete\');',
    ]);

    expect($index->search($routeUsage, 'checkout.pay'))->toBeEmpty();
});
