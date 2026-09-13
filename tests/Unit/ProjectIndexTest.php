<?php

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\ProjectIndex;
use Illuminate\Container\Container;

/**
 * Build an index whose only provider fails the given number of times before answering.
 *
 * @return array{index: ProjectIndex, provider: DataProvider&object{calls: int}}
 */
function projectIndexFailing(int $failures): array
{
    $provider = new class($failures) implements DataProvider
    {
        public int $calls = 0;

        public function __construct(protected int $failures) {}

        public function get(): array
        {
            $this->calls++;

            if ($this->calls <= $this->failures) {
                throw ScriptFailedException::forInvalidJson('php: not found');
            }

            return ['front.page'];
        }

        public function patterns(): array
        {
            return [];
        }
    };

    $container = new Container;
    $container->instance('views.provider', $provider);

    $index = new class($container) extends ProjectIndex
    {
        protected array $providers = [
            'views' => 'views.provider',
        ];
    };

    return ['index' => $index, 'provider' => $provider];
}

test('a load that fails is not kept as an empty index', function () {
    ['index' => $index] = projectIndexFailing(failures: 1);

    expect(fn () => $index->get('views'))->toThrow(ScriptFailedException::class);

    expect($index->get('views'))->toBe(['front.page']);
});

test('a load that succeeds is kept', function () {
    ['index' => $index, 'provider' => $provider] = projectIndexFailing(failures: 0);

    $index->get('views');
    $index->get('views');

    expect($provider->calls)->toBe(1);
});
