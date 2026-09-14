<?php

use App\Lsp\Document;
use App\Lsp\Features\Eloquent\EloquentCompletionProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

/**
 * Get the labels the Eloquent provider completes at the end of the given statement.
 *
 * @return array<int, string>
 */
function eloquentCompletionLabels(string $statement): array
{
    $index = new class extends ProjectIndex
    {
        public function __construct()
        {
            $this->loaded['models'] = [
                'App\\Models\\Customer' => [
                    'class'      => 'App\\Models\\Customer',
                    'attributes' => [['name' => 'id'], ['name' => 'name']],
                    'relations'  => [
                        ['name' => 'orders', 'type' => 'HasMany', 'related' => 'App\\Models\\Order'],
                        ['name' => 'site', 'type' => 'HasOne', 'related' => 'App\\Models\\Site'],
                    ],
                ],
                'App\\Models\\Order' => [
                    'class'      => 'App\\Models\\Order',
                    'attributes' => [['name' => 'id'], ['name' => 'status'], ['name' => 'customer_id']],
                    'relations'  => [
                        ['name' => 'customer', 'type' => 'BelongsTo', 'related' => 'App\\Models\\Customer'],
                        ['name' => 'lines', 'type' => 'HasMany', 'related' => 'App\\Models\\OrderLine'],
                    ],
                ],
                'App\\Models\\OrderLine' => [
                    'class'      => 'App\\Models\\OrderLine',
                    'attributes' => [['name' => 'id'], ['name' => 'quantity']],
                    'relations'  => [],
                ],
                'App\\Models\\Site' => [
                    'class'      => 'App\\Models\\Site',
                    'attributes' => [['name' => 'id'], ['name' => 'domain']],
                    'relations'  => [],
                ],
            ];
        }
    };

    $project = new Project(FileUri::of('file:///project'), [], $index, new ScriptRunner('/project', ['php']));
    $content = "<?php\n\nuse App\\Models\\Customer;\n\n" . $statement;
    $lines = explode("\n", $content);
    $document = new Document('file:///project/app/Probe.php', $content);
    $position = ['line' => count($lines) - 1, 'character' => strlen(end($lines))];

    return array_column((new EloquentCompletionProvider($project))->get($document, $position), 'label');
}

test('completes the relation names of the model being queried', function () {
    expect(eloquentCompletionLabels("Customer::query()->with('"))->toBe(['orders', 'site']);
    expect(eloquentCompletionLabels("Customer::query()->whereHas('"))->toBe(['orders', 'site']);
    expect(eloquentCompletionLabels("Customer::query()->with(['"))->toBe(['orders', 'site']);
});

test('completes the relations of a relation, nested with dots', function () {
    expect(eloquentCompletionLabels("Customer::query()->with('orders."))->toBe(['orders.customer', 'orders.lines']);
    expect(eloquentCompletionLabels("Customer::query()->with('orders.lines."))->toBe([]);
    expect(eloquentCompletionLabels("Customer::query()->with('unknown."))->toBe([]);
});

test('completes the attributes of the related model inside the closure of a relation', function () {
    expect(eloquentCompletionLabels("Customer::query()->whereHas('orders', fn (\$query) => \$query->where('"))
        ->toBe(['id', 'status', 'customer_id']);
    expect(eloquentCompletionLabels("Customer::query()->with(['orders.lines' => fn (\$query) => \$query->where('"))
        ->toBe(['id', 'quantity']);
});
