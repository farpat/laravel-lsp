<?php

declare(strict_types=1);

namespace App\Lsp\Listeners;

use App\Lsp\Contracts\ExceptionHandler;
use App\Lsp\Contracts\Listener;
use App\Lsp\Document;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Server;
use App\Lsp\Transport\JsonRpcRequest;

class PublishDiagnostics implements Listener
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected Server $server,
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected ExceptionHandler $exceptions,
    ) {}

    /**
     * Handle the incoming LSP notification.
     */
    public function handle(JsonRpcRequest $request): void
    {
        $document = $this->documents->get(
            $request->get('textDocument.uri')
        );

        if ($document === null) {
            return;
        }

        $this->publish($document);
    }

    /**
     * Publish diagnostics for the given document.
     */
    public function publish(Document $document): void
    {
        $diagnostics = [];

        foreach ($this->features->diagnostics() as $provider) {
            try {
                array_push($diagnostics, ...$provider->get($document));
            } catch (ScriptFailedException $e) {
                // A project that cannot be read has no "not found" to report:
                // the other providers still publish theirs.
                $this->exceptions->report($e);
            }
        }

        $this->server->notify('textDocument/publishDiagnostics', [
            'uri' => $document->uri,
            'diagnostics' => $diagnostics,
        ]);
    }
}
