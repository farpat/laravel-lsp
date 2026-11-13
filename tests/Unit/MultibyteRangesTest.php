<?php

use App\Parser\DetectWalker;

/**
 * Get where the first string argument found in the given code starts and ends.
 *
 * @return array{start: array<string, int>, end: array<string, int>}
 */
function firstStringRange(string $code): array
{
    $string = (new DetectWalker($code))->walk()->first()['arguments']['children'][0]['children'][0];

    return ['start' => $string['start'], 'end' => $string['end']];
}

it('places a string after an emoji in UTF-16 units, as the editor does', function () {
    expect(firstStringRange("<?php /* é😀 */ route('home');"))->toBe([
        'start' => ['line' => 0, 'column' => 22],
        'end'   => ['line' => 0, 'column' => 26],
    ]);
});

it('counts a string holding an emoji in UTF-16 units', function () {
    expect(firstStringRange("<?php route('😀home');"))->toBe([
        'start' => ['line' => 0, 'column' => 12],
        'end'   => ['line' => 0, 'column' => 18],
    ]);
});

it('places a string of a Blade echo after an emoji in UTF-16 units', function () {
    expect(firstStringRange("<p>é😀</p> {{ route('home') }}"))->toBe([
        'start' => ['line' => 0, 'column' => 20],
        'end'   => ['line' => 0, 'column' => 24],
    ]);
});
