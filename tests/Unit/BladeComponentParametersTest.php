<?php

use App\Parser\DetectWalker;

/**
 * Every string argument the walker detects, as [method, value, start line, start character].
 *
 * @return array<int, array{string, string, int, int}>
 */
function detectedStringArguments(string $blade): array
{
    return (new DetectWalker($blade))->walk()
        ->map(fn (array $item): array => [
            $item['methodName'],
            $item['arguments']['children'][0]['children'][0]['value'],
            $item['arguments']['children'][0]['children'][0]['start']['line'],
            $item['arguments']['children'][0]['children'][0]['start']['column'],
        ])
        ->values()
        ->all();
}

test('detects the expression bound to a blade component parameter', function () {
    $blade = <<<'BLADE'
    <x-layout.error
        code="404"
        :heading="__('errors.404.heading')"
        :message="__('errors.404.message')" />
    BLADE;

    expect(detectedStringArguments($blade))->toBe([
        ['__', 'errors.404.heading', 2, 17],
        ['__', 'errors.404.message', 3, 17],
    ]);
});

test('detects every echo written in a blade component parameter', function () {
    $blade = <<<'BLADE'
    <x-alert title="{{ __('alerts.title') }} {{ __('alerts.suffix') }}" body="{!! __('alerts.body') !!}" />
    BLADE;

    expect(detectedStringArguments($blade))->toBe([
        ['__', 'alerts.title', 0, 22],
        ['__', 'alerts.suffix', 0, 47],
        ['__', 'alerts.body', 0, 81],
    ]);
});

test('detects a parameter expression written on a later line of the tag', function () {
    $blade = <<<'BLADE'
    <x-alert
        :title="
            __('alerts.title')
        " />
    BLADE;

    expect(detectedStringArguments($blade))->toBe([
        ['__', 'alerts.title', 2, 11],
    ]);
});

test('still detects the echoes written outside of any component', function () {
    $blade = <<<'BLADE'
    <x-alert :title="__('alerts.title')" />
    <div>{{ __('alerts.body') }}</div>
    BLADE;

    expect(detectedStringArguments($blade))->toBe([
        ['__', 'alerts.title', 0, 20],
        ['__', 'alerts.body', 1, 11],
    ]);
});
