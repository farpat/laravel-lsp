<?php

declare(strict_types=1);

/*
 * The options a client can send are written twice: read from the code as
 * `$project->boolean('...')`, and listed by hand in the README. Nothing ties the two
 * together, so a feature ships with an option nobody can find.
 */

/**
 * Get every boolean option the server reads.
 *
 * @return array<int, string>
 */
function optionsInCode(): array
{
    $found = [];

    foreach (phpFilesIn(__DIR__ . '/../../app/Lsp') as $file) {
        $source = (string) file_get_contents($file);

        preg_match_all("/boolean\(\s*'([A-Za-z]+)'/", $source, $read);
        // A reference source names its own option, which it hands to `boolean()` later.
        preg_match_all("/function setting\(\): string\s*\{\s*return '([A-Za-z]+)';/", $source, $named);

        array_push($found, ...$read[1], ...$named[1]);
    }

    sort($found);

    return array_values(array_unique($found));
}

/**
 * Get every option the given README sections list in their tables.
 *
 * @return array<int, string>
 */
function optionsInReadme(string ...$sections): array
{
    $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
    $found = [];

    foreach ($sections as $section) {
        $body = explode('### ', explode($section, $readme)[1] ?? '')[0];

        // An option is written in camel case, which is what tells it apart from the other
        // things a section quotes, such as `php` or `auto`.
        preg_match_all('/`([a-z]+[A-Z][A-Za-z]*)`/', $body, $matches);

        array_push($found, ...$matches[1]);
    }

    sort($found);

    return array_values(array_unique($found));
}

/**
 * Get every PHP file of the given directory.
 *
 * @return array<int, string>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

test('every option the server reads is documented', function () {
    $documented = optionsInReadme('Server Options', 'Feature Options', 'Reference Options');

    expect(array_values(array_diff(optionsInCode(), $documented)))->toBe([]);
});

test('every documented feature option is read by the server', function () {
    $documented = optionsInReadme('Feature Options', 'Reference Options');

    expect(array_values(array_diff($documented, optionsInCode())))->toBe([]);
});
