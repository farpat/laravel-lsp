<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\ExceptionHandler;
use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\Support\CompletionItems;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentCompletion implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected Project $project,
        protected ExceptionHandler $exceptions,
    ) {}

    /**
     * Handle the textDocument/completion request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get(
            (string) $request->get('textDocument.uri')
        );

        if ($document === null) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $position = $request->get('position', []);

        if (!is_array($position)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        if (!is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        foreach ($this->features->completions() as $provider) {
            try {
                $items = $provider->get($document, $position);
            } catch (ScriptFailedException $e) {
                // A project that cannot be read loses this feature, not the others.
                $this->exceptions->report($e);
                $items = [];
            }

            if ($items !== []) {
                return JsonRpcResponse::result(
                    $request->id(),
                    CompletionItems::matching($document, $items),
                );
            }

            $request->cancelIfRequested();
        }

        return JsonRpcResponse::result($request->id(), []);
    }
}
