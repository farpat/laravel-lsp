<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\CodeActions\CodeActionContext;
use App\Lsp\Contracts\ExceptionHandler;
use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentCodeAction implements Method
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
     * Handle the textDocument/codeAction request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get(
            (string) $request->get('textDocument.uri')
        );

        if ($document === null) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $range = $request->get('range', []);
        $context = $request->get('context', []);

        if (!is_array($range) || !is_array($context)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $actions = [];
        $codeActionContext = new CodeActionContext($range, $context);

        foreach ($this->features->codeActions() as $provider) {
            try {
                array_push($actions, ...$provider->get($document, $codeActionContext));
            } catch (ScriptFailedException $e) {
                // A project that cannot be read loses this feature, not the others.
                $this->exceptions->report($e);
            }

            $request->cancelIfRequested();
        }

        return JsonRpcResponse::result($request->id(), $actions);
    }
}
