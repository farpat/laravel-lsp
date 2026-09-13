<?php

use App\Lsp\Contracts\DiagnosticProvider;
use App\Lsp\Contracts\Transport;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Listeners\PublishDiagnostics;
use App\Lsp\Server;
use App\Lsp\Transport\JsonRpcRequest;
use Illuminate\Container\Container;
use Psr\Log\NullLogger;

/**
 * Build a transport that keeps every message the server sends.
 */
function recordingTransport(): Transport
{
    return new class implements Transport
    {
        /** @var array<int, array<string, mixed>> */
        public array $sent = [];

        public function onReceive(Closure $handler): void {}

        public function dispatch(JsonRpcRequest $request, Closure $dispatch): void {}

        public function cancel(int|string $id): void {}

        public function run(): void {}

        public function send(string $message): void
        {
            $this->sent[] = json_decode($message, true);
        }
    };
}

/**
 * Build a provider answering the given diagnostics, or throwing when given none.
 *
 * @param  array<int, array<string, mixed>>|null  $diagnostics
 */
function diagnosticProvider(?array $diagnostics): DiagnosticProvider
{
    return new class($diagnostics) implements DiagnosticProvider
    {
        public function __construct(protected ?array $diagnostics) {}

        public function get(Document $document): array
        {
            if ($this->diagnostics === null) {
                throw ScriptFailedException::forInvalidJson('php: not found');
            }

            return $this->diagnostics;
        }
    };
}

test('a provider whose project script fails publishes nothing while the others still do', function () {
    $transport = recordingTransport();
    $container = new Container;

    new Server($transport, new NullLogger, $container);

    $container->instance('failing.provider', diagnosticProvider(null));
    $container->instance('working.provider', diagnosticProvider([['message' => 'Route [home] not found.']]));

    $container->make(FeatureRegistry::class)->diagnostics = ['failing.provider', 'working.provider'];
    $container->make(DocumentManager::class)->open('file:///project/routes/web.php', '<?php');

    $container->make(PublishDiagnostics::class)->handle(JsonRpcRequest::from([
        'jsonrpc' => '2.0',
        'method'  => 'textDocument/didOpen',
        'params'  => ['textDocument' => ['uri' => 'file:///project/routes/web.php']],
    ]));

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['method'])->toBe('textDocument/publishDiagnostics')
        ->and($transport->sent[0]['params']['diagnostics'])->toBe([['message' => 'Route [home] not found.']]);
});
