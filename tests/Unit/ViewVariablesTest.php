<?php

use App\Lsp\Document;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\PhpMembers;
use App\Lsp\Support\PhpType;
use App\Lsp\Support\ViewCallSites;

test('reads the variables a call site hands to the view', function () {
    $built = throwawayProject([
        'resources/views/front/home.blade.php'    => '{{ $title }}',
        'app/Http/Controllers/HomeController.php' => <<<'PHP'
        <?php

        class HomeController
        {
            public function __invoke(Pricing $pricing)
            {
                $subtitle = 'hello';

                return view('front.home', [
                    'title' => 'Welcome',
                    'pricing' => $pricing,
                ])->with(compact('subtitle'))->with('extra', 42);
            }
        }
        PHP,
    ]);

    $document = new Document(
        (string) FileUri::fromPath($built['path'] . '/resources/views/front/home.blade.php'),
        '{{ $title }}',
    );

    $variables = (new ViewCallSites($built['project'], $built['index']))->passedTo($document);
    $names = array_map(fn ($variable): string => $variable->name, $variables);

    // `compact()` names a variable without saying what it holds, so it reads last.
    expect($names)->toBe(['title', 'pricing', 'subtitle', 'extra']);
    expect($variables[0]->value)->toBe("'Welcome'");
    expect($variables[2]->isSpelledOut())->toBeFalse();
    expect($variables[3]->value)->toBe('42');
});

test('reads the type the calling file writes down', function () {
    $built = throwawayProject([
        'app/Http/Controllers/HomeController.php' => <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Pricing\Plan;

        class HomeController
        {
            public function __invoke(Plan $plan)
            {
                return view('home', ['plan' => $plan, 'count' => 3, 'made' => new Plan]);
            }
        }
        PHP,
    ]);

    $path = $built['path'] . '/app/Http/Controllers/HomeController.php';
    $source = (string) file_get_contents($path);
    $types = new PhpType($built['project'], $built['index']);

    expect($types->of('$plan', $path, strpos($source, "'plan' => ") ?: 0))->toBe('\App\Pricing\Plan');
    expect($types->of('3', $path, 0))->toBe('int');
    expect($types->of('new Plan', $path, 0))->toBe('\Plan');
    expect($types->of('$unknown', $path, 0))->toBeNull();
});

test('offers only what a template may read on a class', function () {
    $members = (new PhpMembers(throwawayProject([])['index']))->publicMembersOf(<<<'PHP'
    <?php

    class Amounts
    {
        public const CENTS = 100;

        public string $label;

        protected int $hidden;

        public function __construct(public readonly int $seats, protected string $secret) {}

        public function total(): int
        {
            return 0;
        }

        private function compute(): int
        {
            return 0;
        }
    }
    PHP);

    expect(array_column($members, 'name'))->toBe(['seats', 'total', 'label', 'CENTS']);
});

test('reads the keys of the array a function hands back', function () {
    $source = <<<'PHP'
    <?php

    class Pricing
    {
        public function toAmounts(): array
        {
            return [
                'setup_fee' => 1,
                'monthly_price' => ['nested' => 2],
            ];
        }
    }
    PHP;

    $members = new PhpMembers(throwawayProject([])['index']);
    $literal = $members->returnedArrayIn($source, (int) strpos($source, 'function toAmounts'));

    expect($members->arrayKeysIn((string) $literal))->toBe(['setup_fee', 'monthly_price']);
});
