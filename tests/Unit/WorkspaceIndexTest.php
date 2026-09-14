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
        @mkdir(dirname($path . '/' . $name), 0777, true);
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
        removeDirectory($directory);
    }
});

function removeDirectory(string $directory): void
{
    foreach (glob($directory . '/{,.}*', GLOB_BRACE) as $entry) {
        if (in_array(basename($entry), ['.', '..'], true)) {
            continue;
        }

        is_dir($entry) ? removeDirectory($entry) : unlink($entry);
    }

    rmdir($directory);
}

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

test('follows a folder moved, which is reported without the files it holds', function () use ($routeUsage) {
    $index = workspaceIndex([
        'app/Handler/Pay.php' => "<?php route('checkout.pay');",
    ]);
    $path = dirname((string) array_key_first($index->files()), 3);

    expect($index->search($routeUsage, 'checkout.pay'))->toHaveCount(1);

    rename($path . '/app/Handler', $path . '/app/Handlers');
    $index->refresh([$path . '/app/Handler', $path . '/app/Handlers']);

    $matches = $index->search($routeUsage, 'checkout.pay');

    expect($matches)->toHaveCount(1);
    expect($matches->first()['path'])->toBe($path . '/app/Handlers/Pay.php');

    rename($path . '/app/Handlers', $path . '/app/Gone');
    $index->refresh([$path . '/app/Handlers']);

    expect($index->search($routeUsage, 'checkout.pay'))->toHaveCount(0);
});
