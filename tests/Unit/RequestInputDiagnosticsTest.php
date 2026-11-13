<?php

use App\Lsp\Document;
use App\Lsp\Features\RequestInputs\RequestInputDiagnosticProvider;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Support\FileUri;

/**
 * Get the inputs reported as not validated in a controller method with the given body.
 *
 * @return array<int, string>
 */
function unvalidatedInputs(string $body): array
{
    $index = new class extends ProjectIndex
    {
        public function __construct()
        {
            $this->loaded['formRequests'] = collect();
        }
    };

    $project = new Project(FileUri::of('file:///project'), [], $index, new ScriptRunner('/project', ['php']));
    $content = <<<PHP
    <?php

    use Illuminate\\Http\\Request;

    class OrderController
    {
        public function store(Request \$request)
        {
    {$body}
        }
    }
    PHP;

    $diagnostics = (new RequestInputDiagnosticProvider($project))
        ->get(new Document('file:///project/app/OrderController.php', $content));

    return array_map(
        fn (array $diagnostic): string => (string) preg_replace('/^Input \[(.*)\].*$/', '$1', $diagnostic['message']),
        $diagnostics,
    );
}

it('reports an input the validate call does not declare', function () {
    expect(unvalidatedInputs(<<<'PHP'
        $request->validate(['name' => 'required']);
        $request->input('email');
    PHP))->toBe(['email']);
});

it('accepts the parent of a nested rule, and the items a wildcard rule covers', function () {
    expect(unvalidatedInputs(<<<'PHP'
        $request->validate([
            'items.*.id' => 'required',
            'address.city' => 'required',
        ]);
        $request->input('items');
        $request->input('items.0.id');
        $request->input('address');
        $request->input('address.city');
        $request->input('address.zip');
    PHP))->toBe(['address.zip']);
});
