<?php

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Contracts\Transport;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Methods\TextDocumentDocumentLink;
use App\Lsp\Project;
use App\Lsp\ProjectIndex;
use App\Lsp\ScriptRunner;
use App\Lsp\Server;
use App\Lsp\Support\FileUri;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

/**
 * Build a provider answering the given links, or throwing when given none.
 *
 * @param  array<int, array<string, mixed>>|null  $links
 */
function linkProvider(?array $links): LinkProvider
{
    return new class($links) implements LinkProvider
    {
        public function __construct(protected ?array $links) {}

        public function get(Document $document): array
        {
            if ($this->links === null) {
                throw ScriptFailedException::forInvalidJson('ParseError syntax error');
            }

            return $this->links;
        }
    };
}

test('a provider whose project script fails gives no links while the others still do', function () {
    $container = new Container;

    new Server(Mockery::mock(Transport::class), new NullLogger, $container);

    $container->instance(Project::class, new Project(
        FileUri::of('file:///project'),
        [],
        Mockery::mock(ProjectIndex::class),
        new ScriptRunner('/project', ['php']),
    ));
    $container->instance('failing.provider', linkProvider(null));
    $container->instance('working.provider', linkProvider([['target' => 'file:///project/config/app.php']]));

    $container->make(FeatureRegistry::class)->links = ['failing.provider', 'working.provider'];
    $container->make(DocumentManager::class)->open('file:///project/app/Models/Notification.php', '<?php');

    $response = $container->make(TextDocumentDocumentLink::class)->handle(JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'textDocument/documentLink',
        'params'  => ['textDocument' => ['uri' => 'file:///project/app/Models/Notification.php']],
    ]));

    expect($response->toArray()['result'])->toBe([['target' => 'file:///project/config/app.php']]);
});
